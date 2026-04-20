<?php

namespace App\Filament\Clusters\Inventory\Resources\StockMovements\Actions\Bulk;

use App\Models\ProductStock;
use App\Services\StockMovement\Actions\CalculateProductStockSnapshotAction;
use App\Services\StockMovement\Actions\RecalculateProductStockFromMovementsAction;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Recalcula e corrige ProductStock para os estoques vinculados às movimentações selecionadas.
 */
final class FixProductStockBulkAction
{
    public static function make(): BulkAction
    {
        return BulkAction::make('fixProductStock')
            ->label('Corrigir Estoque')
            ->icon(Heroicon::Wrench)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Corrigir Saldos de Estoque')
            ->modalDescription('Recalcula saldo total, saldo reservado, custo médio e metadados do último movimento dos estoques vinculados às movimentações selecionadas, usando as movimentações ativas como fonte da verdade. Esta operação é irreversível.')
            ->modalSubmitActionLabel('Corrigir')
            ->modalSubmitAction(fn($action) => $action->color('warning'))
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

                $fixed      = 0;
                $skipped    = 0;
                $failed     = 0;
                $action     = new RecalculateProductStockFromMovementsAction();
                $calculator = app(CalculateProductStockSnapshotAction::class);

                foreach ($stockIds as $stockId) {
                    try {
                        DB::transaction(function () use ($stockId, $action, $calculator, &$fixed, &$skipped): void {
                            $stock = ProductStock::where('id', $stockId)
                                ->lockForUpdate()
                                ->first();

                            if (!$stock) {
                                return;
                            }

                            $expected = $calculator->calculate($stock);
                            $diff = $calculator->diff($stock, $expected);

                            if ($diff === []) {
                                $skipped++;
                                return;
                            }

                            $result = $action->recalculate($stock);

                            Log::info('FixProductStockBulkAction: Estoque recalculado', [
                                'product_stock_id' => $stockId,
                                'diff'             => $diff,
                                'result'           => $result,
                            ]);

                            $fixed++;
                        });
                    } catch (\Throwable $e) {
                        $failed++;

                        Log::error('FixProductStockBulkAction: Falha ao recalcular estoque', [
                            'product_stock_id' => $stockId,
                            'exception'        => $e->getMessage(),
                        ]);
                    }
                }

                $total = $stockIds->count();

                if ($failed === 0) {
                    Notification::make()
                        ->title("✓ {$fixed} corrigido(s), {$skipped} já consistente(s), de {$total} estoque(s).")
                        ->success()
                        ->send();
                } else {
                    Notification::make()
                        ->title("{$fixed} corrigido(s), {$skipped} consistente(s), {$failed} falha(s) de {$total} estoque(s).")
                        ->body('Verifique os logs para detalhes das falhas.')
                        ->warning()
                        ->persistent()
                        ->send();
                }
            });
    }
}
