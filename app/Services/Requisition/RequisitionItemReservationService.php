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
                $reserved = (float) $ledger->get($stock->id, collect())->sum(fn (StockMovement $entry) => $entry->type === Type::RESERVATION ? $entry->resolvedBaseQuantity() : -$entry->resolvedBaseQuantity());
                $desired = $target && (int) $target->id === (int) $stock->id ? $item->resolvedBaseQuantity() : 0;
                $delta = round($desired - $reserved, 8);
                if (abs($delta) < 0.0001) {
                    continue;
                }
                $service = app(StockMovementService::class);
                $movement = $service->create([
                    'company_id' => $stock->company_id, 'product_id' => $stock->product_id, 'product_stock_id' => $stock->id,
                    'type' => $delta > 0 ? Type::RESERVATION->value : Type::RESERVATION_RELEASE->value,
                    'operational_unit' => $stock->product->unit->value, 'operational_quantity' => abs($delta),
                    'base_unit' => $stock->product->unit->value, 'base_quantity' => abs($delta), 'conversion_factor_snapshot' => 1,
                    'quantity' => abs($delta), 'unit_price' => (float) $item->unit_price,
                    'source_type' => 'requisition_item', 'source_id' => $item->id,
                    'reason' => $deleting ? 'Liberação por exclusão do item' : 'Ajuste de reserva do item da requisição',
                ], $userId);
                if (! $movement || $service->hasError()) {
                    throw ValidationException::withMessages(['quantity' => $service->getMessage() ?: 'Não foi possível ajustar a reserva.']);
                }
            }
            $reserved = StockMovement::withoutGlobalScope(RoleRecordScope::class)->where('company_id', $requisition->company_id)->where('source_type', 'requisition_item')
                ->whereIn('source_id', $requisition->items()->lockForUpdate()->pluck('id')->push($item->id))->whereIn('type', [Type::RESERVATION->value, Type::RESERVATION_RELEASE->value])->lockForUpdate()->get()
                ->sum(fn (StockMovement $entry) => $entry->type === Type::RESERVATION ? $entry->resolvedBaseQuantity() : -$entry->resolvedBaseQuantity());
            $requisition->update(['stock_reserved' => $reserved > 0.0001]);
        });
    }
}
