<?php

namespace App\Services\Product;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Access\CompanyAuthorization;
use App\Services\Access\UserAccessService;
use App\Services\Audit\AuditRecorder;
use App\Services\StockMovement\StockMovementService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductInventoryService
{
    public function canAdjustCosts(User $user, Company $company): bool
    {
        $access = app(CompanyAuthorization::class);
        $membership = $access->membership($user, $company);

        return $user->isActive() && $company->is_active && $membership
            && app(UserAccessService::class)->allows($user, $company, 'product_stocks.update')
            && (($membership->access_mode === 'legacy' && $membership->role === 'owner')
                || $access->allows($user, $company, 'settings.manage'));
    }

    public function authorize(Product $product, User $user, Company $company, string $permission): void
    {
        abort_unless((int) $product->company_id === (int) $company->id
            && app(UserAccessService::class)->allows($user, $company, $permission), 403);
        $access = app(CompanyAuthorization::class);
        if (str_starts_with($permission, 'products.')
            && $access->requiresRoleAuthorization($user, $company, Filament::getCurrentPanel()?->getId())) {
            abort_unless($access->allowsRecord($user, $company, $permission, $product), 403);
        }
    }

    public function saveConfiguration(Product $product, array $data, User $user, Company $company, string $permission = 'products.update'): void
    {
        $this->authorize($product, $user, $company, $permission);
        $validated = Validator::make($data, [
            'allow_negative' => ['required', 'boolean'],
            'quantity_minimum' => ['required', 'numeric', 'min:0'],
            'quantity_maximum' => ['nullable', 'numeric', 'gte:quantity_minimum'],
        ])->validate();

        if (! $product->has_stock_control) {
            return;
        }

        DB::transaction(function () use ($product, $validated, $user, $company): void {
            $stock = $this->stock($product, $company);
            $before = $stock->only(array_keys($validated));
            $stock->update([...$validated, 'updated_by' => $user->id]);
            $this->record($product, 'product.inventory.updated', 'Configurações de estoque atualizadas', $before, $stock->only(array_keys($validated)), $user);
        });
    }

    public function adjustCosts(Product $product, array $data, User $user, Company $company): void
    {
        $this->authorize($product, $user, $company, 'products.update');
        abort_unless($this->canAdjustCosts($user, $company), 403);
        $data = Validator::make($data, [
            'average_cost' => ['required', 'numeric', 'min:0'],
            'last_cost' => ['required', 'numeric', 'min:0'],
            'reason' => ['required', 'string', 'max:500'],
        ])->validate();

        DB::transaction(function () use ($product, $data, $user, $company): void {
            $stock = $this->stock($product, $company);
            $before = $stock->only(['average_cost', 'last_cost']);
            $stock->update(['average_cost' => $data['average_cost'], 'last_cost' => $data['last_cost'], 'updated_by' => $user->id]);
            $this->record($product, 'product.costs.updated', 'Ajuste administrativo de custos', $before, $stock->only(['average_cost', 'last_cost']), $user, ['reason' => $data['reason']]);
        });
    }

    public function move(Product $product, array $data, User $user, Company $company, bool $count = false): StockMovement
    {
        $this->authorize($product, $user, $company, 'stock_movements.create');
        $data = Validator::make($data, $count ? [
            'counted_quantity' => ['required', 'numeric', 'min:0'],
            'reason' => ['required', 'string', 'max:500'],
        ] : [
            'type' => ['required', Rule::in(['entry', 'exit', 'loss'])],
            'quantity' => ['required', 'numeric', 'min:0.001'],
            'operational_unit' => ['required', 'string'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'reason' => ['required', 'string', 'max:500'],
        ])->validate();

        return DB::transaction(function () use ($product, $data, $user, $company, $count): StockMovement {
            $stock = $this->stock($product, $company);
            if ($count) {
                $delta = round($data['counted_quantity'] - (float) $stock->quantity_total, 3);
                if (abs($delta) < 0.001) {
                    throw ValidationException::withMessages(['counted_quantity' => 'A contagem já corresponde ao saldo atual.']);
                }
                $data = [...$data, 'type' => $delta > 0 ? 'entry' : 'exit', 'quantity' => abs($delta),
                    'operational_unit' => $product->unit->value, 'unit_price' => (float) $stock->average_cost,
                    'additional_info' => ['operation' => 'inventory_count', 'previous_quantity' => $stock->quantity_total, 'counted_quantity' => $data['counted_quantity']],
                    'reason' => 'Contagem: '.$data['reason']];
            }
            $service = app(StockMovementService::class);
            $movement = $service->create([...$data, 'product_id' => $product->id, 'company_id' => $company->id,
                'product_stock_id' => $stock->id, 'source_type' => 'manual', 'source_id' => 0], $user->id);
            if (! $movement || $service->hasError()) {
                throw ValidationException::withMessages($service->getErrors() ?: ['quantity' => $service->getMessageUser()]);
            }

            return $movement;
        });
    }

    private function stock(Product $product, Company $company): ProductStock
    {
        if (! $product->fresh()->has_stock_control) {
            throw ValidationException::withMessages(['stock' => 'Ative e salve o controle de estoque antes de realizar esta operação.']);
        }

        return ProductStock::where('product_id', $product->id)->where('company_id', $company->id)->lockForUpdate()->firstOrFail();
    }

    public function record(Product $product, string $event, string $summary, array $before, array $after, User $user, array $metadata = []): void
    {
        app(AuditRecorder::class)->recordModelEvent($product, $event, $summary, $before, $after, $user->id, metadata: $metadata);
    }
}
