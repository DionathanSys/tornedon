<?php

namespace App\Services\FiscalDocument;

use App\Enum\FiscalDocument\OperationType;
use App\Enum\StockMovement\Type;
use App\Models\FiscalDocument;
use App\Models\FiscalStockEffect;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\RequisitionItem;
use App\Models\StockMovement;
use App\Services\Access\RoleRecordScope;
use App\Services\Product\ProductUnitConversionService;
use App\Services\Requisition\RequisitionItemReservationService;
use App\Services\Requisition\RequisitionStockService;
use App\Services\StockMovement\StockMovementService;
use App\Traits\HandlesServiceResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaleNfeStockService
{
    use HandlesServiceResponse;

    public function applies(FiscalDocument $document): bool
    {
        return $document->isNfe() && $document->invoice_id && $document->operation_type === OperationType::SAIDA && ! $document->isPurchaseReturn() && ! $document->isOperationalHistory();
    }

    /** Prepare immutable unit/policy snapshots and validate the whole note, not each line in isolation. */
    public function prepare(FiscalDocument $document): void
    {
        if (! $this->applies($document)) {
            return;
        }
        DB::transaction(function () use ($document): void {
            $invoice = $document->invoice()->where('company_id', $document->company_id)->lockForUpdate()->firstOrFail();
            $document = FiscalDocument::where('company_id', $invoice->company_id)->lockForUpdate()->findOrFail($document->id);
            $items = $document->items()->orderBy('id')->lockForUpdate()->get();
            $sourceIds = $items->pluck('requisition_item_id')->filter();
            if ($sourceIds->count() !== $sourceIds->unique()->count()) {
                throw ValidationException::withMessages(['stock' => 'Cada item fiscal deve ter uma origem comercial única nesta NF-e.']);
            }
            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['stock' => 'A NF-e não possui itens para validar o estoque.']);
            }
            foreach ($items as $item) {
                $existing = $document->stockEffects()->where('fiscal_document_item_id', $item->id)->first();
                if ($existing?->processed_at) {
                    continue;
                }
                $product = Product::where('company_id', $document->company_id)->findOrFail($item->product_id);
                $source = null;
                if ($item->requisition_item_id) {
                    $source = RequisitionItem::whereHas('requisition', fn ($query) => $query->where('company_id', $document->company_id)->where('invoice_id', $invoice->id))
                        ->lockForUpdate()->find($item->requisition_item_id);
                    if (! $source) {
                        throw ValidationException::withMessages(['stock' => 'A origem do item fiscal não pertence a esta fatura e empresa.']);
                    }
                }
                $allDisabled = $invoice->requisitions()->exists() && ! $invoice->requisitions()->where('stock_control_enabled', true)->exists();
                $controlled = ! $allDisabled && $product->has_stock_control;
                if ($source) {
                    $controlled = $source->requisition->stock_control_enabled !== false
                        && ((int) $source->product_id === (int) $product->id ? ($source->stock_control_snapshot ?? $product->has_stock_control) : $product->has_stock_control);
                }
                if ($controlled && ! $source) {
                    throw ValidationException::withMessages(['stock' => 'Item fiscal sem vínculo rastreado à requisição. Revise a origem antes de baixar estoque.']);
                }
                if ($controlled && $source) {
                    $reservedUnit = StockMovement::withoutGlobalScope(RoleRecordScope::class)->where('company_id', $document->company_id)
                        ->where('source_type', 'requisition_item')->where('source_id', $source->id)->where('type', Type::RESERVATION->value)->latest('id')->value('base_unit');
                    if ($reservedUnit && $reservedUnit !== $product->unit->value) {
                        throw ValidationException::withMessages(['stock' => 'A unidade base mudou após a reserva. Regularize as unidades do estoque antes de emitir.']);
                    }
                }
                $signature = [$item->product_id, (string) $item->unit_of_measure, (string) $item->quantity];
                if ($existing && ($existing->snapshot['signature'] ?? null) === $signature) {
                    $quantity = (float) $existing->base_quantity;
                    $baseUnit = $existing->base_unit;
                    $factor = $existing->snapshot['factor'];
                    $controlled = $existing->stock_control_enabled;
                    if ($controlled && $baseUnit !== $product->unit->value) {
                        throw ValidationException::withMessages(['stock' => 'A unidade base mudou após a preparação fiscal. Regularize o estoque antes de processar.']);
                    }
                } else {
                    if ($source && (int) $source->product_id === (int) $product->id && (string) $source->unit_of_measure === (string) $item->unit_of_measure && $source->conversion_factor_snapshot) {
                        $factor = (float) $source->conversion_factor_snapshot;
                        $quantity = round((float) $item->quantity * $factor, 8);
                        $baseUnit = $product->unit->value;
                    } else {
                        $conversion = app(ProductUnitConversionService::class)->convertToBase($product, (string) $item->unit_of_measure, (float) $item->quantity);
                        $quantity = $conversion->baseQuantity;
                        $baseUnit = $conversion->baseUnit;
                        $factor = $conversion->factor;
                    }
                }
                $stock = $controlled ? ProductStock::where('company_id', $document->company_id)->where('product_id', $product->id)->first() : null;
                if ($controlled && ($quantity < 0.001 || abs($quantity - round($quantity, 3)) > 0.0000001)) {
                    throw ValidationException::withMessages(['stock' => 'Quantidade do item #'.$item->id.' incompatível com a precisão de três casas do estoque.']);
                }
                if ($controlled && (! $stock || ! $stock->is_active)) {
                    throw ValidationException::withMessages(['stock' => 'Produto #'.$product->id.' sem estoque ativo.']);
                }
                $reuse = null;
                if ($controlled && $source) {
                    $prior = FiscalStockEffect::where('company_id', $document->company_id)->where('requisition_item_id', $source->id)->whereNotNull('stock_movement_id')->whereNotNull('processed_at')->orderBy('id')->first();
                    if ($prior) {
                        if ((int) $prior->fiscal_document_id !== (int) $document->id && ! $prior->fiscalDocument->isFiscalCancellationConfirmed()) {
                            throw ValidationException::withMessages(['stock' => 'A origem comercial já possui uma NF-e com saída fiscal ativa.']);
                        }
                        if ((int) $prior->product_id !== (int) $product->id || abs((float) $prior->base_quantity - $quantity) > 0.0001 || $prior->base_unit !== $baseUnit) {
                            throw ValidationException::withMessages(['stock' => 'A requisição já possui saída física com produto/quantidade diferente. Regularize o estoque antes de emitir outra nota.']);
                        }
                        $reuse = $prior->stock_movement_id;
                    } elseif ($source->stock_consumed_at || StockMovement::withoutGlobalScope(RoleRecordScope::class)->where('company_id', $document->company_id)->where('source_type', 'requisition_item')->where('source_id', $source->id)->where('type', Type::EXIT->value)->exists()) {
                        throw ValidationException::withMessages(['stock' => 'Existe baixa legada na requisição sem vínculo fiscal validado. Não será gerada outra saída automaticamente.']);
                    }
                }
                FiscalStockEffect::updateOrCreate(['fiscal_document_item_id' => $item->id], [
                    'company_id' => $document->company_id, 'fiscal_document_id' => $document->id, 'requisition_item_id' => $source?->id,
                    'product_id' => $product->id, 'product_stock_id' => $stock?->id, 'stock_control_enabled' => $controlled,
                    'base_quantity' => $quantity, 'base_unit' => $baseUnit, 'snapshot' => ['signature' => $signature, 'factor' => $factor, 'reuse_movement_id' => $reuse],
                    'status' => 'prepared', 'last_error' => null,
                ]);
            }
            $pending = $document->stockEffects()->whereNull('processed_at')->where('stock_control_enabled', true)->get()
                ->reject(fn ($effect) => ! empty($effect->snapshot['reuse_movement_id']));
            foreach (ProductStock::where('company_id', $document->company_id)->whereIn('id', $pending->pluck('product_stock_id'))->orderBy('id')->lockForUpdate()->get() as $stock) {
                if (! $stock->is_active) {
                    throw ValidationException::withMessages(['stock' => 'O estoque do produto #'.$stock->product_id.' está inativo.']);
                }
                if ($stock->allow_negative) {
                    continue;
                }
                $effects = $pending->where('product_stock_id', $stock->id);
                $required = (float) $effects->sum('base_quantity');
                $own = 0;
                foreach ($effects->pluck('requisition_item_id')->unique() as $sourceId) {
                    $own += $this->reserved($stock->company_id, $sourceId, $stock->id);
                }
                if ($required > (float) $stock->quantity_available + $own + 0.0001 || $required > (float) $stock->quantity_total + 0.0001) {
                    throw ValidationException::withMessages(['stock' => 'Saldo insuficiente antes do envio da NF-e para o produto #'.$stock->product_id.'. As reservas da própria nota foram consideradas.']);
                }
            }
        });
    }

    public function apply(FiscalDocument $document, ?int $actorId = null): bool
    {
        $this->resetResponse();
        if (! $this->applies($document)) {
            $this->setSuccess();

            return true;
        }
        try {
            DB::transaction(function () use ($document, $actorId): void {
                $invoice = $document->invoice()->where('company_id', $document->company_id)->lockForUpdate()->firstOrFail();
                $document = FiscalDocument::where('company_id', $invoice->company_id)->lockForUpdate()->findOrFail($document->id);
                if (! $document->isNfeAuthorized()) {
                    throw ValidationException::withMessages(['stock' => 'A saída exige NF-e autorizada.']);
                }
                $this->prepare($document);
                $effects = $document->stockEffects()->whereNull('processed_at')->orderBy('id')->lockForUpdate()->get();
                ProductStock::where('company_id', $document->company_id)->whereIn('id', $effects->pluck('product_stock_id')->filter())->orderBy('id')->lockForUpdate()->get();
                $userId = $actorId ?? $document->confirmed_by ?? $document->updated_by ?? $document->created_by ?? $invoice->created_by;
                if (! $userId) {
                    throw ValidationException::withMessages(['stock' => 'Responsável pela saída não identificado.']);
                }
                foreach ($effects->pluck('requisition_item_id')->filter()->unique() as $sourceId) {
                    app(RequisitionItemReservationService::class)->sync(RequisitionItem::findOrFail($sourceId), $userId, true);
                }
                foreach ($effects as $effect) {
                    $source = $effect->requisition_item_id ? RequisitionItem::findOrFail($effect->requisition_item_id) : null;
                    $movementId = $effect->snapshot['reuse_movement_id'] ?? null;
                    if ($effect->stock_control_enabled && ! $movementId) {
                        $item = $effect->fiscalDocumentItem;
                        $service = app(StockMovementService::class);
                        $movement = $service->create([
                            'company_id' => $document->company_id, 'product_id' => $effect->product_id, 'product_stock_id' => $effect->product_stock_id,
                            'type' => Type::EXIT->value, 'operational_unit' => $item->unit_of_measure, 'operational_quantity' => (float) $item->quantity,
                            'base_unit' => $effect->base_unit, 'base_quantity' => (float) $effect->base_quantity, 'conversion_factor_snapshot' => $effect->snapshot['factor'],
                            'quantity' => (float) $effect->base_quantity, 'unit_price' => (float) $item->unit_price,
                            'source_type' => 'fiscal_document_item', 'source_id' => $item->id, 'reason' => 'Saída por autorização da NF-e #'.$document->id,
                            'additional_info' => ['fiscal_document_id' => $document->id, 'requisition_item_id' => $source?->id],
                        ], $userId);
                        if (! $movement || $service->hasError()) {
                            throw ValidationException::withMessages(['stock' => $service->getMessage() ?: 'Falha ao aplicar saída fiscal.']);
                        }
                        $movementId = $movement->id;
                    }
                    $effect->update(['stock_movement_id' => $movementId, 'status' => $effect->stock_control_enabled ? 'applied' : 'not_applicable', 'processed_at' => now(), 'last_error' => null, 'snapshot' => [...$effect->snapshot, 'processed_by' => $userId]]);
                    if ($source && $effect->stock_control_enabled) {
                        app(RequisitionStockService::class)->markItemAsConsumed($source);
                    }
                }
                foreach ($invoice->requisitions as $requisition) {
                    app(RequisitionStockService::class)->syncConsumptionFlags($requisition);
                }
            });
            $this->setSuccess('Saída fiscal processada sem duplicidade.');

            return true;
        } catch (\Throwable $exception) {
            $message = $exception instanceof ValidationException ? implode(' ', $exception->validator->errors()->all()) : $exception->getMessage();
            $authorized = $document->fresh()?->isNfeAuthorized() ?? false;
            foreach ($authorized ? $document->items()->whereNotNull('product_id')->get() : [] as $item) {
                $effect = $document->stockEffects()->where('fiscal_document_item_id', $item->id)->first();
                if ($effect?->processed_at) {
                    continue;
                }
                FiscalStockEffect::updateOrCreate(['fiscal_document_item_id' => $item->id], [
                    'company_id' => $document->company_id, 'fiscal_document_id' => $document->id, 'product_id' => $item->product_id,
                    'stock_control_enabled' => $effect?->stock_control_enabled ?? true,
                    'base_quantity' => $effect?->base_quantity ?? $item->quantity, 'base_unit' => $effect?->base_unit ?? $item->unit_of_measure,
                    'snapshot' => $effect?->snapshot ?? [], 'status' => 'blocked', 'last_error' => $message,
                ]);
            }
            $this->setError(($authorized ? 'NF-e autorizada com pendência de estoque: ' : '').$message);

            return false;
        }
    }

    private function reserved(int $companyId, int $sourceId, int $stockId): float
    {
        return max(0, (float) StockMovement::withoutGlobalScope(RoleRecordScope::class)->where('company_id', $companyId)->where('product_stock_id', $stockId)->where('source_type', 'requisition_item')->where('source_id', $sourceId)
            ->whereIn('type', [Type::RESERVATION->value, Type::RESERVATION_RELEASE->value])->get()
            ->sum(fn (StockMovement $entry) => $entry->type === Type::RESERVATION ? $entry->resolvedBaseQuantity() : -$entry->resolvedBaseQuantity()));
    }
}
