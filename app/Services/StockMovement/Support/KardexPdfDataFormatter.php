<?php

namespace App\Services\StockMovement\Support;

use App\Models\Product;
use App\Models\StockMovement;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class KardexPdfDataFormatter
{
    /**
     * @param  array{start_date?: string|null, end_date?: string|null}  $filters
     * @return array<string, mixed>
     */
    public function format(Product $product, int $companyId, array $filters = []): array
    {
        $startDate = filled($filters['start_date'] ?? null)
            ? Carbon::parse($filters['start_date'])->startOfDay()
            : null;

        $endDate = filled($filters['end_date'] ?? null)
            ? Carbon::parse($filters['end_date'])->endOfDay()
            : null;

        $baseQuery = StockMovement::query()
            ->where('company_id', $companyId)
            ->where('product_id', $product->id);

        $openingMovements = $startDate
            ? (clone $baseQuery)
                ->where('created_at', '<', $startDate)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get()
            : collect();

        $openingBalances = $this->calculateBalances($openingMovements);

        $movements = (clone $baseQuery)
            ->when($startDate, fn ($query) => $query->where('created_at', '>=', $startDate))
            ->when($endDate, fn ($query) => $query->where('created_at', '<=', $endDate))
            ->with(['createdBy'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $runningTotal = $openingBalances['stock_balance'];
        $runningReserved = $openingBalances['reserved_balance'];
        $totalEntries = 0.0;
        $totalExits = 0.0;
        $totalReservations = 0.0;
        $totalReservationReleases = 0.0;

        $rows = $movements->map(function (StockMovement $movement) use ($product, &$runningTotal, &$runningReserved, &$totalEntries, &$totalExits, &$totalReservations, &$totalReservationReleases): array {
            $baseQuantity = $movement->resolvedBaseQuantity();
            $stockDelta = $movement->type->applyDelta($baseQuantity);
            $reservedDelta = $movement->type->applyReservationDelta($baseQuantity);

            if ($stockDelta > 0) {
                $totalEntries += $stockDelta;
            }

            if ($stockDelta < 0) {
                $totalExits += abs($stockDelta);
            }

            if ($reservedDelta > 0) {
                $totalReservations += $reservedDelta;
            }

            if ($reservedDelta < 0) {
                $totalReservationReleases += abs($reservedDelta);
            }

            $runningTotal += $stockDelta;
            $runningReserved = max(0, $runningReserved + $reservedDelta);

            return [
                'date' => $movement->created_at?->format('d/m/Y H:i') ?? '-',
                'type' => $movement->type->abbreviation(),
                'reference' => $this->formatReference($movement),
                'user' => $movement->createdBy?->name ?? '-',
                'operational_quantity' => $this->formatQuantity($movement->resolvedOperationalQuantity()),
                'operational_unit' => $movement->operational_unit ?? $movement->base_unit ?? $product->unit?->value ?? 'UN',
                'base_quantity' => $this->formatQuantity($baseQuantity),
                'base_unit' => $movement->base_unit ?? $product->unit?->value ?? 'UN',
                'entry_quantity' => $stockDelta > 0 ? $this->formatQuantity($stockDelta) : '-',
                'exit_quantity' => $stockDelta < 0 ? $this->formatQuantity(abs($stockDelta)) : '-',
                'reservation_quantity' => $reservedDelta > 0 ? $this->formatQuantity($reservedDelta) : '-',
                'release_quantity' => $reservedDelta < 0 ? $this->formatQuantity(abs($reservedDelta)) : '-',
                'stock_balance' => $this->formatQuantity($runningTotal),
                'reserved_balance' => $this->formatQuantity($runningReserved),
                'available_balance' => $this->formatQuantity($runningTotal - $runningReserved),
                'unit_price' => $movement->unit_price !== null ? 'R$ ' . number_format((float) $movement->unit_price, 2, ',', '.') : '-',
                'total_amount' => $movement->total_amount !== null ? 'R$ ' . number_format((float) $movement->total_amount, 2, ',', '.') : '-',
            ];
        });

        return [
            'title' => 'Kardex de Estoque',
            'generated_at' => now()->format('d/m/Y H:i'),
            'company_name' => $product->company?->name ?? 'Empresa',
            'product' => [
                'id' => $product->id,
                'code' => $product->product_code,
                'name' => $product->name,
                'unit' => $product->unit?->value ?? 'UN',
            ],
            'period' => [
                'start' => $startDate?->format('d/m/Y') ?? 'Início',
                'end' => $endDate?->format('d/m/Y') ?? 'Atual',
            ],
            'opening' => [
                'stock_balance' => $this->formatQuantity($openingBalances['stock_balance']),
                'reserved_balance' => $this->formatQuantity($openingBalances['reserved_balance']),
                'available_balance' => $this->formatQuantity($openingBalances['stock_balance'] - $openingBalances['reserved_balance']),
            ],
            'summary' => [
                'entries' => $this->formatQuantity($totalEntries),
                'exits' => $this->formatQuantity($totalExits),
                'reservations' => $this->formatQuantity($totalReservations),
                'releases' => $this->formatQuantity($totalReservationReleases),
                'closing_stock_balance' => $this->formatQuantity($runningTotal),
                'closing_reserved_balance' => $this->formatQuantity($runningReserved),
                'closing_available_balance' => $this->formatQuantity($runningTotal - $runningReserved),
            ],
            'rows' => $rows->all(),
        ];
    }

    /**
     * @param  Collection<int, StockMovement>  $movements
     * @return array{stock_balance: float, reserved_balance: float}
     */
    private function calculateBalances(Collection $movements): array
    {
        $stockBalance = 0.0;
        $reservedBalance = 0.0;

        foreach ($movements as $movement) {
            $baseQuantity = $movement->resolvedBaseQuantity();
            $stockBalance += $movement->type->applyDelta($baseQuantity);
            $reservedBalance = max(0, $reservedBalance + $movement->type->applyReservationDelta($baseQuantity));
        }

        return [
            'stock_balance' => $stockBalance,
            'reserved_balance' => $reservedBalance,
        ];
    }

    private function formatReference(StockMovement $movement): string
    {
        if (blank($movement->source_type) && blank($movement->source_id)) {
            return '-';
        }

        $sourceType = $movement->source_type ? ucfirst(str_replace('_', ' ', $movement->source_type)) : 'Origem';
        $sourceId = $movement->source_id ?: '-';

        return sprintf('%s #%s', $sourceType, $sourceId);
    }

    private function formatQuantity(float $quantity): string
    {
        return number_format($quantity, 3, ',', '.');
    }
}
