<?php

namespace App\Console\Commands;

use App\Models\ProductStock;
use App\Services\StockMovement\Actions\CalculateProductStockSnapshotAction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Comando de reconciliação entre ProductStock e StockMovements.
 *
 * Detecta (e opcionalmente corrige) disparidades entre o saldo armazenado
 * no ProductStock e o que seria calculado somando todas as movimentações ativas.
 *
 * Uso:
 *   php artisan stock:reconcile                     — relatório, sem corrigir
 *   php artisan stock:reconcile --fix               — corrige as disparidades
 *   php artisan stock:reconcile --company=5         — limita a uma empresa
 *   php artisan stock:reconcile --stock=12          — limita a um ProductStock
 *   php artisan stock:reconcile --company=5 --fix   — corrige uma empresa
 */
class ReconcileProductStockCommand extends Command
{
    private const FIELDS_TO_FIX = [
        'quantity_total',
        'quantity_reserved',
        'average_cost',
        'last_cost',
        'last_sale_price',
        'last_movement_date',
        'last_movement_type',
    ];

    protected $signature = 'stock:reconcile
        {--company= : ID da empresa (opcional, processa todas se omitido)}
        {--stock=   : ID de um ProductStock específico (opcional)}
        {--fix      : Aplica as correções automaticamente}
        {--tolerance=0.001 : Tolerância numérica para considerar divergência}';

    protected $description = 'Valida e (opcionalmente) corrige divergências entre ProductStock e StockMovements';

    /** Tolerância para comparação de floats */
    private float $tolerance;

    private CalculateProductStockSnapshotAction $calculator;

    public function handle(): int
    {
        $this->tolerance = (float) $this->option('tolerance');
        $this->calculator = app(CalculateProductStockSnapshotAction::class);
        $fix             = (bool)  $this->option('fix');
        $companyId       = $this->option('company')  ? (int) $this->option('company')  : null;
        $stockId         = $this->option('stock')     ? (int) $this->option('stock')    : null;

        $this->info('');
        $this->info('════════════════════════════════════════════════════════');
        $this->info('  Reconciliação de Estoque — ' . now()->format('d/m/Y H:i:s'));
        $this->info('  Modo: ' . ($fix ? '<fg=yellow>CORREÇÃO AUTOMÁTICA</>' : '<fg=cyan>SOMENTE RELATÓRIO</>'));
        $this->info('════════════════════════════════════════════════════════');

        $query = ProductStock::query()->withTrashed(false); // somente ativos

        if ($stockId) {
            $query->where('id', $stockId);
        } elseif ($companyId) {
            $query->where('company_id', $companyId);
        }

        $stocks  = $query->get();
        $total   = $stocks->count();
        $checked = 0;
        $diverged = 0;
        $fixed   = 0;

        $this->info("  Registros a verificar: {$total}");
        $this->info('');

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        foreach ($stocks as $stock) {
            $checked++;
            $result = $this->checkStock($stock, $fix);

            if ($result['has_divergence']) {
                $diverged++;
                if ($result['fixed']) {
                    $fixed++;
                }
            }

            $bar->advance();
        }

        $bar->finish();
        $this->info('');
        $this->info('');
        $this->line('────────────────────────────────────────────────────────');
        $this->info("  Verificados : {$checked}");

        if ($diverged === 0) {
            $this->info('  <fg=green>Nenhuma divergência encontrada. ✓</>');
        } else {
            $this->warn("  Divergências: {$diverged}");
            if ($fix) {
                $this->info("  Corrigidos  : {$fixed}");
            } else {
                $this->warn('  Execute com --fix para corrigir automaticamente.');
            }
        }

        $this->line('────────────────────────────────────────────────────────');
        $this->info('');

        return self::SUCCESS;
    }

    /* ─────────────────────────────────────────────────────────── */

    private function checkStock(ProductStock $stock, bool $fix): array
    {
        $expected = $this->calculator->calculate($stock);
        $diff = $this->calculator->diff($stock, $expected, $this->tolerance);
        $hasDivergence = $diff !== [];

        if ($hasDivergence) {
            $this->newLine();
            $this->warn("  ⚠  ProductStock #{$stock->id} (produto #{$stock->product_id}, empresa #{$stock->company_id})");
            $this->renderDiff($diff);

            Log::warning('ReconcileProductStockCommand: Divergência detectada', [
                'product_stock_id'       => $stock->id,
                'product_id'             => $stock->product_id,
                'company_id'             => $stock->company_id,
                'diff'                   => $diff,
            ]);
        }

        $wasFixed = false;

        if ($hasDivergence && $fix) {
            try {
                DB::transaction(function () use ($stock, $expected) {
                    // Bloqueia o registro para evitar race condition durante a correção
                    $locked = ProductStock::where('id', $stock->id)->lockForUpdate()->first();

                    if (!$locked) {
                        return;
                    }

                    $locked->update($this->onlyFixableFields($expected));
                });

                $this->line('     <fg=green>→ Corrigido com sucesso.</>');
                $wasFixed = true;

                Log::info('ReconcileProductStockCommand: Divergência corrigida', [
                    'product_stock_id' => $stock->id,
                    'applied'          => $expected,
                ]);
            } catch (\Throwable $e) {
                $this->error("     → Falha ao corrigir: {$e->getMessage()}");

                Log::error('ReconcileProductStockCommand: Falha ao corrigir divergência', [
                    'product_stock_id' => $stock->id,
                    'exception'        => $e->getMessage(),
                ]);
            }
        }

        return [
            'has_divergence' => $hasDivergence,
            'fixed'          => $wasFixed,
        ];
    }

    /**
     * @param  array<string, array{stored:mixed, expected:mixed}>  $diff
     */
    private function renderDiff(array $diff): void
    {
        if (isset($diff['quantity_total'])) {
            $stored = (float) $diff['quantity_total']['stored'];
            $expected = (float) $diff['quantity_total']['expected'];
            $this->line(sprintf(
                '     qty_total        : armazenado=<fg=red>%s</> | esperado=<fg=green>%s</> | diff=%s',
                number_format($stored, 3, ',', '.'),
                number_format($expected, 3, ',', '.'),
                number_format($stored - $expected, 3, ',', '.'),
            ));
        }

        if (isset($diff['quantity_reserved'])) {
            $stored = (float) $diff['quantity_reserved']['stored'];
            $expected = (float) $diff['quantity_reserved']['expected'];
            $this->line(sprintf(
                '     qty_reserved     : armazenado=<fg=red>%s</> | esperado=<fg=green>%s</> | diff=%s',
                number_format($stored, 3, ',', '.'),
                number_format($expected, 3, ',', '.'),
                number_format($stored - $expected, 3, ',', '.'),
            ));
        }

        if (isset($diff['average_cost'])) {
            $stored = (float) $diff['average_cost']['stored'];
            $expected = (float) $diff['average_cost']['expected'];
            $this->line(sprintf(
                '     average_cost     : armazenado=<fg=red>%s</> | esperado=<fg=green>%s</> | diff=%s',
                number_format($stored, 4, ',', '.'),
                number_format($expected, 4, ',', '.'),
                number_format($stored - $expected, 4, ',', '.'),
            ));
        }

        foreach (['last_cost', 'last_sale_price', 'last_movement_date', 'last_movement_type'] as $field) {
            if (isset($diff[$field])) {
                $this->line(sprintf(
                    '     %-16s: armazenado=<fg=red>%s</> | esperado=<fg=green>%s</>',
                    $field,
                    $diff[$field]['stored'] ?? '-',
                    $diff[$field]['expected'] ?? '-',
                ));
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function onlyFixableFields(array $expected): array
    {
        return array_intersect_key($expected, array_flip(self::FIELDS_TO_FIX));
    }
}
