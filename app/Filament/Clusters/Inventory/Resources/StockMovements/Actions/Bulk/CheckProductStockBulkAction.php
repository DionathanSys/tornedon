<?php

namespace App\Filament\Clusters\Inventory\Resources\StockMovements\Actions\Bulk;

use App\Models\ProductStock;
use App\Services\StockMovement\Actions\CalculateProductStockSnapshotAction;
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
    public static function make(): BulkAction
    {
        return BulkAction::make('checkProductStock')
            ->label('Verificar Estoque')
            ->icon(Heroicon::MagnifyingGlass)
            ->color('info')
            ->requiresConfirmation()
            ->modalHeading('Verificar Consistência do Estoque')
            ->modalDescription('Analisa os estoques vinculados às movimentações selecionadas e reporta divergências entre saldo total, saldo reservado, custo e metadados do último movimento. Nenhum dado será alterado.')
            ->modalSubmitActionLabel('Verificar')
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records): void {
                $calculator = app(CalculateProductStockSnapshotAction::class);

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
                    $expected = $calculator->calculate($stock);
                    $diff = $calculator->diff($stock, $expected);

                    if ($diff !== []) {
                        $productName = $stock->product?->name ?? "Estoque #{$stock->id}";
                        $lines = ["**{$productName}** (stock #{$stock->id})"];

                        self::appendDiffLines($lines, $diff);

                        $divergences[] = implode("\n", $lines);

                        Log::warning('CheckProductStockBulkAction: Divergência detectada', [
                            'product_stock_id'       => $stock->id,
                            'product_id'             => $stock->product_id,
                            'diff'                   => $diff,
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

    /**
     * @param  array<int, string>  $lines
     * @param  array<string, array{stored:mixed, expected:mixed}>  $diff
     */
    private static function appendDiffLines(array &$lines, array $diff): void
    {
        if (isset($diff['quantity_total'])) {
            $stored = (float) $diff['quantity_total']['stored'];
            $expected = (float) $diff['quantity_total']['expected'];
            $lines[] = '- Qtde total: armazenado=' . number_format($stored, 3, ',', '.')
                . ' | esperado=' . number_format($expected, 3, ',', '.')
                . ' | diff=' . number_format($stored - $expected, 3, ',', '.');
        }

        if (isset($diff['quantity_reserved'])) {
            $stored = (float) $diff['quantity_reserved']['stored'];
            $expected = (float) $diff['quantity_reserved']['expected'];
            $lines[] = '- Qtde reservada: armazenado=' . number_format($stored, 3, ',', '.')
                . ' | esperado=' . number_format($expected, 3, ',', '.')
                . ' | diff=' . number_format($stored - $expected, 3, ',', '.');
        }

        if (isset($diff['average_cost'])) {
            $stored = (float) $diff['average_cost']['stored'];
            $expected = (float) $diff['average_cost']['expected'];
            $lines[] = '- Custo médio: armazenado=R$ ' . number_format($stored, 2, ',', '.')
                . ' | esperado=R$ ' . number_format($expected, 2, ',', '.')
                . ' | diff=R$ ' . number_format($stored - $expected, 2, ',', '.');
        }

        if (isset($diff['last_cost'])) {
            $lines[] = '- Último custo: armazenado=' . self::formatMoneyOrDash($diff['last_cost']['stored'])
                . ' | esperado=' . self::formatMoneyOrDash($diff['last_cost']['expected']);
        }

        if (isset($diff['last_sale_price'])) {
            $lines[] = '- Último preço de venda: armazenado=' . self::formatMoneyOrDash($diff['last_sale_price']['stored'])
                . ' | esperado=' . self::formatMoneyOrDash($diff['last_sale_price']['expected']);
        }

        if (isset($diff['last_movement_date'])) {
            $lines[] = '- Data do último movimento: armazenado=' . ($diff['last_movement_date']['stored'] ?? '-')
                . ' | esperado=' . ($diff['last_movement_date']['expected'] ?? '-');
        }

        if (isset($diff['last_movement_type'])) {
            $lines[] = '- Tipo do último movimento: armazenado=' . ($diff['last_movement_type']['stored'] ?? '-')
                . ' | esperado=' . ($diff['last_movement_type']['expected'] ?? '-');
        }
    }

    private static function formatMoneyOrDash(mixed $value): string
    {
        if ($value === null) {
            return '-';
        }

        return 'R$ ' . number_format((float) $value, 2, ',', '.');
    }
}
