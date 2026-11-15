<?php

namespace App\Services\StockMovement\Actions;

use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Services\Access\RoleRecordScope;

class CaptureProductStockOpeningSnapshotAction
{
    /** Chamado com o estoque bloqueado, antes de alterar seu histórico. */
    public function execute(ProductStock $stock): void
    {
        if (isset($stock->additional_info['movement_opening_snapshot'])) {
            return;
        }

        $hasHistory = StockMovement::withoutGlobalScope(RoleRecordScope::class)
            ->where('product_stock_id', $stock->id)
            ->where('company_id', $stock->company_id)
            ->where('product_id', $stock->product_id)->exists();
        $calculator = app(CalculateStockMovementStateAction::class);

        $stock->update(['additional_info' => [
            ...($stock->additional_info ?? []),
            // Histórico legado já contém a evolução; não somar o saldo atual novamente.
            'movement_opening_snapshot' => $hasHistory ? $calculator->emptyState() : $calculator->fromStock($stock),
        ]]);
    }
}
