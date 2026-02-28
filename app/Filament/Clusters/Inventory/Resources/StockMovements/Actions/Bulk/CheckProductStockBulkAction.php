<?php

namespace App\Filament\Clusters\Inventory\Resources\StockMovements\Actions\Bulk;

use App\Enum\StockMovement\Type;
use App\Models\ProductStock;
use App\Models\StockMovement;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Verifica disparidades entre ProductStock e StockMovements para os registros selecionados.
 * Modo relatório — não altera nada no banco.
 */
final class CheckProductStockBulkAction
{
    private const TOLERANCE = 0.001;

    public static function make(): BulkAction
    {
        return BulkAction::make('checkProductStock')
            ->label('Verificar Estoque')
            ->icon(Heroicon::MagnifyingGlass)
            ->color('info')
            ->requiresConfirmation()
            ->modalHeading('Verificar Consistência do Estoque')
            ->modalDescription('Analisa os estoques vinculados às movimentações selecionadas e reporta divergências entre os saldos armazenados e o recálculo por movimentações. Nenhum dado será alterado.')
            ->modalSubmitActionLabel('Verificar')
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records): void {
                // Coleta IDs únicos de ProductStock das movimentações selecionadas
                $stockIds = $records->pluck('product_stock_id')->unique()->filter()->values();

                if ($stockIds->isEmpty()) {
                    Notification::make()
                        ->title('Nenhum estoque encontrado nas movimentações selecionadas.')
                        ->warning()
                        ->send();
                    return;
                }

                $divergences = [];
                $totalChecked = 0;

                foreach ($stockIds as $stockId) {
                    $stock = ProductStock::with('product')->find($stockId);
                    if (!$stock) {
                        continue;
                    }

                    $totalChecked++;
                    $expected = self::calculateExpected($stockId);

                    $storedQty = round((float) $stock->quantity_available, 3);
                    $expQty    = round($expected['quantity_available'], 3);
                    $storedAvg = round((float) $stock->average_cost, 4);
                    $expAvg    = round($expected['average_cost'], 4);

                    $qtyDiv = abs($storedQty - $expQty) > self::TOLERANCE;
                    $avgDiv = abs($storedAvg - $expAvg) > self::TOLERANCE;

                    if ($qtyDiv || $avgDiv) {
                        $productName = $stock->product?->name ?? "Estoque #{$stock->id}";
                        $lines = ["**{$productName}** (stock #{$stock->id})"];

                        if ($qtyDiv) {
                            $lines[] = "- Qtde: armazenado=" . number_format($storedQty, 3, ',', '.') .
                                ' | esperado=' . number_format($expQty, 3, ',', '.') .
                                ' | diff=' . number_format($storedQty - $expQty, 3, ',', '.');
                        }
                        if ($avgDiv) {
                            $lines[] = "- Custo médio: armazenado=R$ " . number_format($storedAvg, 2, ',', '.') .
                                ' | esperado=R$ ' . number_format($expAvg, 2, ',', '.') .
                                ' | diff=R$ ' . number_format($storedAvg - $expAvg, 2, ',', '.');
                        }

                        $divergences[] = implode("\n", $lines);

                        Log::warning('CheckProductStockBulkAction: Divergência detectada', [
                            'product_stock_id'       => $stock->id,
                            'product_id'             => $stock->product_id,
                            'stored_qty_available'   => $storedQty,
                            'expected_qty_available' => $expQty,
                            'stored_average_cost'    => $storedAvg,
                            'expected_average_cost'  => $expAvg,
                        ]);
                    }
                }

                if (empty($divergences)) {
                    Notification::make()
                        ->title("✓ {$totalChecked} estoque(s) verificado(s) — nenhuma divergência encontrada.")
                        ->success()
                        ->send();
                    return;
                }

                $count = count($divergences);

                Notification::make()
                    ->title("⚠ {$count} divergência(s) encontrada(s) em {$totalChecked} estoque(s) verificado(s).")
                    ->body(implode("\n\n", $divergences))
                    ->warning()
                    ->persistent()
                    ->send();
            });
    }

    // ── Lógica de cálculo (espelho do ReconcileProductStockCommand) ──────────

    private static function calculateExpected(int $productStockId): array
    {
        $movements = StockMovement::where('product_stock_id', $productStockId)
            ->whereNull('deleted_at')
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $quantityAvailable = 0.0;
        $totalInboundCost  = 0.0;
        $totalInboundQty   = 0.0;
        $lastCost          = null;
        $lastSalePrice     = null;

        foreach ($movements as $movement) {
            /** @var Type $type */
            $type      = $movement->type;
            $quantity  = (float) $movement->quantity;
            $unitPrice = $movement->unit_price !== null ? (float) $movement->unit_price : null;

            $delta = $type->applyDelta($quantity);
            $quantityAvailable += $delta;

            if ($type->isInbound() && $unitPrice !== null && $unitPrice > 0) {
                $totalInboundQty  += abs($quantity);
                $totalInboundCost += abs($quantity) * $unitPrice;
                $lastCost = $unitPrice;
            } elseif ($type === Type::ADJUSTMENT && $delta > 0 && $unitPrice !== null && $unitPrice > 0) {
                $totalInboundQty  += $delta;
                $totalInboundCost += $delta * $unitPrice;
                $lastCost = $unitPrice;
            }

            if ($type->isOutbound() && $unitPrice !== null && $unitPrice > 0) {
                $lastSalePrice = $unitPrice;
            }
        }

        return [
            'quantity_available' => round($quantityAvailable, 3),
            'average_cost'       => $totalInboundQty > 0
                ? round($totalInboundCost / $totalInboundQty, 4)
                : 0.0,
            'last_cost'      => $lastCost,
            'last_sale_price' => $lastSalePrice,
        ];
    }
}
