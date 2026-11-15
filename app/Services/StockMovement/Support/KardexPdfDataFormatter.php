<?php

namespace App\Services\StockMovement\Support;

use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Services\StockMovement\Actions\CalculateStockMovementStateAction;
use Carbon\Carbon;

class KardexPdfDataFormatter
{
    public function __construct(private readonly CalculateStockMovementStateAction $calculator) {}

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

        $stock = ProductStock::query()->where('company_id', $companyId)->where('product_id', $product->id)->first();
        $state = $stock?->additional_info['movement_opening_snapshot'] ?? $this->calculator->emptyState();
        foreach ($openingMovements as $movement) {
            $state = $this->calculator->calculate($state, $movement);
        }
        $openingState = $state;

        $movements = (clone $baseQuery)
            ->when($startDate, fn ($query) => $query->where('created_at', '>=', $startDate))
            ->when($endDate, fn ($query) => $query->where('created_at', '<=', $endDate))
            ->with(['createdBy'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $totalEntries = 0.0;
        $totalExits = 0.0;
        $totalReservations = 0.0;
        $totalReservationReleases = 0.0;

        $rows = $movements->map(function (StockMovement $movement) use ($product, &$state, &$totalEntries, &$totalExits, &$totalReservations, &$totalReservationReleases): array {
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

            $state = $this->calculator->calculate($state, $movement);
            $runningTotal = $state['quantity_total'];
            $runningReserved = $state['quantity_reserved'];

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
                'operational_unit_price' => ! $movement->isAdministrativeCostAdjustment() && $movement->unit_price !== null
                    ? $this->formatMoney((float) $movement->unit_price) : '-',
                'average_cost' => $this->formatMoney($state['average_cost'], 4),
                'stock_value' => $this->formatMoney($runningTotal * $state['average_cost']),
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
                'stock_balance' => $this->formatQuantity($openingState['quantity_total']),
                'reserved_balance' => $this->formatQuantity($openingState['quantity_reserved']),
                'available_balance' => $this->formatQuantity($openingState['quantity_total'] - $openingState['quantity_reserved']),
                'average_cost' => $this->formatMoney($openingState['average_cost'], 4),
                'stock_value' => $this->formatMoney($openingState['quantity_total'] * $openingState['average_cost']),
            ],
            'summary' => [
                'entries' => $this->formatQuantity($totalEntries),
                'exits' => $this->formatQuantity($totalExits),
                'reservations' => $this->formatQuantity($totalReservations),
                'releases' => $this->formatQuantity($totalReservationReleases),
                'closing_stock_balance' => $this->formatQuantity($state['quantity_total']),
                'closing_reserved_balance' => $this->formatQuantity($state['quantity_reserved']),
                'closing_available_balance' => $this->formatQuantity($state['quantity_total'] - $state['quantity_reserved']),
                'closing_average_cost' => $this->formatMoney($state['average_cost'], 4),
                'closing_stock_value' => $this->formatMoney($state['quantity_total'] * $state['average_cost']),
                'closing_reserved_value' => $this->formatMoney($state['quantity_reserved'] * $state['average_cost']),
                'closing_available_value' => $this->formatMoney(($state['quantity_total'] - $state['quantity_reserved']) * $state['average_cost']),
            ],
            'rows' => $rows->all(),
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

    private function formatMoney(float $value, int $decimals = 2): string
    {
        return 'R$ '.number_format($value, $decimals, ',', '.');
    }
}
