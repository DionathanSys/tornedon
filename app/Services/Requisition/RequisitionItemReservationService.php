<?php

namespace App\Services\Requisition;

use App\Enum\StockMovement\Type;
use App\Models\ProductStock;
use App\Models\RequisitionItem;
use App\Models\StockMovement;
use App\Services\Access\RoleRecordScope;
use App\Services\StockMovement\StockMovementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RequisitionItemReservationService
{
    /** Reconcile against the ledger, so repeated events cannot duplicate reservations. */
    public function sync(RequisitionItem $item, int $userId, bool $deleting = false): void
    {
        $userId = $userId > 0 ? $userId : (int) ($item->updated_by ?? $item->created_by ?? 0);
        DB::transaction(function () use ($item, $userId, $deleting): void {
            $requisition = $item->requisition()->lockForUpdate()->firstOrFail();
            $product = $item->product()->firstOrFail();
            if ((int) $product->company_id !== (int) $requisition->company_id) {
                throw ValidationException::withMessages(['product_id' => 'O produto deve pertencer à empresa da requisição.']);
            }
            $controlled = $requisition->stock_control_enabled !== false && ($item->stock_control_snapshot ?? $product->has_stock_control);
            $target = ! $deleting && ! $item->stock_consumed_at && $controlled
                ? ProductStock::where('company_id', $requisition->company_id)->where('product_id', $product->id)->first() : null;
            if (! $deleting && $controlled && ! $item->stock_consumed_at && (! $target || ! $target->is_active)) {
                throw ValidationException::withMessages(['product_id' => 'Cadastre um estoque ativo para o produto antes de reservá-lo.']);
            }
            // Balance includes every operator's events for this verified company/origin.
            $ledger = StockMovement::withoutGlobalScope(RoleRecordScope::class)->where('company_id', $requisition->company_id)->where('source_type', 'requisition_item')->where('source_id', $item->id)
                ->whereIn('type', [Type::RESERVATION->value, Type::RESERVATION_RELEASE->value])->lockForUpdate()->get()->groupBy('product_stock_id');
            $ids = $ledger->filter(fn ($entries) => $entries->sum(fn (StockMovement $entry) => $entry->type === Type::RESERVATION ? $entry->resolvedBaseQuantity() : -$entry->resolvedBaseQuantity()) > 0.0001)
                ->keys()->merge($target ? [$target->id] : [])->unique()->sort()->values();
            $stocks = ProductStock::where('company_id', $requisition->company_id)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            if ($stocks->count() !== $ids->count()) {
                throw ValidationException::withMessages(['stock' => 'Uma reserva anterior referencia estoque indisponível. Regularize o registro antes de alterar o item.']);
            }
            foreach ($stocks as $stock) {
                $entries = $ledger->get($stock->id, collect());
                $reserved = (float) $entries->sum(fn (StockMovement $entry) => $entry->type === Type::RESERVATION ? $entry->resolvedBaseQuantity() : -$entry->resolvedBaseQuantity());
                $previous = $entries->where('type', Type::RESERVATION)->sortBy('id')->last();
                $desired = $target && (int) $target->id === (int) $stock->id ? $item->resolvedBaseQuantity() : 0;
                $factor = (float) $item->quantity > 0 ? $item->resolvedBaseQuantity() / (float) $item->quantity : 1;
                // A unit/price change must release the old commercial snapshot first.
                if ($reserved > 0.0001 && $desired > 0 && $previous && (
                    $previous->operational_unit !== $item->unit_of_measure
                    || abs((float) $previous->conversion_factor_snapshot - $factor) > 0.00000001
                    || abs((float) $previous->unit_price - (float) $item->unit_price) > 0.0001
                )) {
                    $this->createMovement($stock, $item, -$reserved, $previous, $userId, $deleting);
                    $reserved = 0;
                }
                $delta = round($desired - $reserved, 8);
                if (abs($delta) < 0.0001) {
                    continue;
                }
                $this->createMovement($stock, $item, $delta, $delta < 0 ? $previous : null, $userId, $deleting);
            }
            $reserved = StockMovement::withoutGlobalScope(RoleRecordScope::class)->where('company_id', $requisition->company_id)->where('source_type', 'requisition_item')
                ->whereIn('source_id', $requisition->items()->lockForUpdate()->pluck('id')->push($item->id))->whereIn('type', [Type::RESERVATION->value, Type::RESERVATION_RELEASE->value])->lockForUpdate()->get()
                ->sum(fn (StockMovement $entry) => $entry->type === Type::RESERVATION ? $entry->resolvedBaseQuantity() : -$entry->resolvedBaseQuantity());
            $requisition->update(['stock_reserved' => $reserved > 0.0001]);
        });
    }

    private function createMovement(ProductStock $stock, RequisitionItem $item, float $delta, ?StockMovement $previous, int $userId, bool $deleting): void
    {
        $factor = $previous
            ? $previous->resolvedBaseQuantity() / $previous->resolvedOperationalQuantity()
            : $item->resolvedBaseQuantity() / (float) $item->quantity;
        $service = app(StockMovementService::class);
        $movement = $service->create([
            'company_id' => $stock->company_id, 'product_id' => $stock->product_id, 'product_stock_id' => $stock->id,
            'type' => $delta > 0 ? Type::RESERVATION->value : Type::RESERVATION_RELEASE->value,
            'operational_unit' => $previous?->operational_unit ?? $item->unit_of_measure ?? $stock->product->unit->value,
            'operational_quantity' => round(abs($delta) / $factor, 8),
            'base_unit' => $previous?->base_unit ?? $stock->product->unit->value,
            'base_quantity' => abs($delta), 'conversion_factor_snapshot' => $factor,
            'quantity' => abs($delta), 'unit_price' => (float) ($previous?->unit_price ?? $item->unit_price),
            'source_type' => 'requisition_item', 'source_id' => $item->id,
            'reason' => $deleting ? 'Liberação por exclusão do item' : 'Ajuste de reserva do item da requisição',
        ], $userId);
        if (! $movement || $service->hasError()) {
            throw ValidationException::withMessages(['quantity' => $service->getMessage() ?: 'Não foi possível ajustar a reserva.']);
        }
    }
}
