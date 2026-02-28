<?php

namespace App\Console\Commands;

use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Enum\StockMovement\Type;
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
    protected $signature = 'stock:reconcile
        {--company= : ID da empresa (opcional, processa todas se omitido)}
        {--stock=   : ID de um ProductStock específico (opcional)}
        {--fix      : Aplica as correções automaticamente}
        {--tolerance=0.001 : Tolerância numérica para considerar divergência}';

    protected $description = 'Valida e (opcionalmente) corrige divergências entre ProductStock e StockMovements';

    /** Tolerância para comparação de floats */
    private float $tolerance;

    public function handle(): int
    {
        $this->tolerance = (float) $this->option('tolerance');
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
        $expected = $this->calculateExpected($stock->id);

        $storedQty = round((float) $stock->quantity_available, 3);
        $expQty    = round($expected['quantity_available'], 3);

        $storedAvg = round((float) $stock->average_cost, 4);
        $expAvg    = round($expected['average_cost'], 4);

        $qtyDivergence = abs($storedQty - $expQty) > $this->tolerance;
        $avgDivergence = abs($storedAvg - $expAvg) > $this->tolerance;

        $hasDivergence = $qtyDivergence || $avgDivergence;

        if ($hasDivergence) {
            $this->newLine();
            $this->warn("  ⚠  ProductStock #{$stock->id} (produto #{$stock->product_id}, empresa #{$stock->company_id})");

            if ($qtyDivergence) {
                $this->line(sprintf(
                    '     qty_available: armazenado=<fg=red>%s</> | esperado=<fg=green>%s</> | diff=%s',
                    number_format($storedQty, 3, ',', '.'),
                    number_format($expQty, 3, ',', '.'),
                    number_format($storedQty - $expQty, 3, ',', '.'),
                ));
            }

            if ($avgDivergence) {
                $this->line(sprintf(
                    '     average_cost : armazenado=<fg=red>%s</> | esperado=<fg=green>%s</> | diff=%s',
                    number_format($storedAvg, 4, ',', '.'),
                    number_format($expAvg, 4, ',', '.'),
                    number_format($storedAvg - $expAvg, 4, ',', '.'),
                ));
            }

            Log::warning('ReconcileProductStockCommand: Divergência detectada', [
                'product_stock_id'       => $stock->id,
                'product_id'             => $stock->product_id,
                'company_id'             => $stock->company_id,
                'stored_qty_available'   => $storedQty,
                'expected_qty_available' => $expQty,
                'stored_average_cost'    => $storedAvg,
                'expected_average_cost'  => $expAvg,
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

                    $locked->update([
                        'quantity_available' => $expected['quantity_available'],
                        'average_cost'       => $expected['average_cost'],
                        'last_cost'          => $expected['last_cost'],
                        'last_sale_price'    => $expected['last_sale_price'],
                        'last_movement_date' => $expected['last_movement_date'],
                        'last_movement_type' => $expected['last_movement_type'],
                    ]);
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

    /* ─────────────────────────────────────────────────────────── */

    /**
     * Recalcula os valores esperados de um ProductStock somando todas
     * as movimentações ativas, sem alterar nada no banco.
     */
    private function calculateExpected(int $productStockId): array
    {
        $movements = StockMovement::where('product_stock_id', $productStockId)
            ->whereNull('deleted_at')
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $quantityAvailable = 0.0;
        $totalInboundCost  = 0.0;
        $totalInboundQty   = 0.0;

        $lastCost         = null;
        $lastSalePrice    = null;
        $lastMovementDate = null;
        $lastMovementType = null;

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

            $lastMovementDate = $movement->created_at->toDateString();
            $lastMovementType = $type->value;
        }

        $averageCost = $totalInboundQty > 0
            ? round($totalInboundCost / $totalInboundQty, 4)
            : 0.0;

        return [
            'quantity_available' => round($quantityAvailable, 3),
            'average_cost'       => $averageCost,
            'last_cost'          => $lastCost,
            'last_sale_price'    => $lastSalePrice,
            'last_movement_date' => $lastMovementDate,
            'last_movement_type' => $lastMovementType,
        ];
    }
}
