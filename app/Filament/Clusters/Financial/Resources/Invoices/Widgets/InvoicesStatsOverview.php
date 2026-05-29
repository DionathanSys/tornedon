<?php

namespace App\Filament\Clusters\Financial\Resources\Invoices\Widgets;

use App\Enum\Invoice\Status;
use App\Filament\Clusters\Financial\Resources\Invoices\Pages\ListInvoices;
use Carbon\Carbon;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Contracts\HasTable;
use Filament\Widgets\Concerns\InteractsWithPageTable;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

use function Livewire\trigger;

class InvoicesStatsOverview extends Widget
{
    use InteractsWithPageTable;

    protected static bool $isDiscovered = false;

    protected string $view = 'filament.clusters.financial.resources.invoices.widgets.invoices-stats-overview';

    protected int | string | array $columnSpan = 'full';

    protected function getTablePage(): string
    {
        return ListInvoices::class;
    }

    protected function getViewData(): array
    {
        $baseQuery = $this->getPageTableQuery()->clone()->reorder();
        $totalInvoices = $baseQuery->clone()->count();
        $pendingInvoices = $baseQuery->clone()->where('status', Status::PENDING->value)->count();
        $totals = $this->resolveTotals($baseQuery->clone());

        return [
            'cards' => [
                [
                    'label' => 'Vlr. Liq. Faturas',
                    'value' => $this->formatMoney($totals['net_value']),
                    'footer_label' => 'Total bruto',
                    'footer_value' => $this->formatMoney($totals['gross_value']),
                    'icon' => $this->toHeroicon(Heroicon::Banknotes),
                    'variant' => 'neutral',
                ],
                $this->resolveCompositionCard($totals),
                $this->resolvePreviousPeriodComparison(),
                [
                    'label' => 'Pendentes',
                    'value' => number_format($pendingInvoices, 0, ',', '.'),
                    'footer_label' => 'Participação',
                    'footer_value' => $this->toPercentage($pendingInvoices, $totalInvoices),
                    'icon' => $this->toHeroicon(Heroicon::Clock),
                    'variant' => 'amber',
                ],
            ],
        ];
    }

    /**
     * @return array{gross_value: float, net_value: float, services_value: float, products_value: float}
     */
    private function resolveTotals(Builder $query): array
    {
        $invoiceIdsQuery = $query->select('invoices.id');

        $serviceOrdersByInvoice = DB::table('service_orders')
            ->leftJoin('service_order_items', 'service_order_items.service_order_id', '=', 'service_orders.id')
            ->whereIn('service_orders.invoice_id', $invoiceIdsQuery)
            ->groupBy('service_orders.id', 'service_orders.travel_value')
            ->selectRaw('COALESCE(SUM(service_order_items.gross_amount), 0) + COALESCE(service_orders.travel_value, 0) as gross_amount')
            ->selectRaw('COALESCE(SUM(service_order_items.total_amount), 0) + COALESCE(service_orders.travel_value, 0) as total_amount');

        $requisitionsByInvoice = DB::table('requisitions')
            ->leftJoin('requisition_items', 'requisition_items.requisition_id', '=', 'requisitions.id')
            ->whereIn('requisitions.invoice_id', $invoiceIdsQuery)
            ->groupBy('requisitions.id')
            ->selectRaw('COALESCE(SUM(requisition_items.gross_amount), 0) as gross_amount')
            ->selectRaw('COALESCE(SUM(requisition_items.total_amount), 0) as total_amount');

        $servicesGross = round(((float) (DB::query()
            ->fromSub($serviceOrdersByInvoice, 'service_order_totals')
            ->selectRaw('COALESCE(SUM(service_order_totals.gross_amount), 0) as gross_amount')
            ->value('gross_amount') ?? 0)) / 100, 2);

        $servicesTotal = round(((float) (DB::query()
            ->fromSub($serviceOrdersByInvoice, 'service_order_totals')
            ->selectRaw('COALESCE(SUM(service_order_totals.total_amount), 0) as total_amount')
            ->value('total_amount') ?? 0)) / 100, 2);

        $productsGross = round(((float) (DB::query()
            ->fromSub($requisitionsByInvoice, 'requisition_totals')
            ->selectRaw('COALESCE(SUM(requisition_totals.gross_amount), 0) as gross_amount')
            ->value('gross_amount') ?? 0)) / 100, 2);

        $productsTotal = round(((float) (DB::query()
            ->fromSub($requisitionsByInvoice, 'requisition_totals')
            ->selectRaw('COALESCE(SUM(requisition_totals.total_amount), 0) as total_amount')
            ->value('total_amount') ?? 0)) / 100, 2);

        return [
            'gross_value' => round($servicesGross + $productsGross, 2),
            'net_value' => round($servicesTotal + $productsTotal, 2),
            'services_value' => $servicesTotal,
            'products_value' => $productsTotal,
        ];
    }

    /**
     * @param array{gross_value: float, net_value: float, services_value: float, products_value: float} $totals
     * @return array{label: string, value: string, footer_label: string, footer_value: string, icon: string, variant: string}
     */
    private function resolveCompositionCard(array $totals): array
    {
        $components = [
            [
                'label' => 'PC',
                'value' => $totals['products_value'],
            ],
            [
                'label' => 'MO',
                'value' => $totals['services_value'],
            ],
        ];

        usort($components, fn (array $left, array $right): int => $right['value'] <=> $left['value']);

        $largest = $components[0];
        $smallest = $components[1];

        return [
            'label' => 'Mix Faturado',
            'value' => sprintf(
                '%s %s (%s)',
                $largest['label'],
                $this->formatMoney($largest['value']),
                $this->toPercentage($largest['value'], $totals['net_value'])
            ),
            'footer_label' => $smallest['label'],
            'footer_value' => sprintf(
                '%s (%s)',
                $this->formatMoney($smallest['value']),
                $this->toPercentage($smallest['value'], $totals['net_value'])
            ),
            'icon' => $this->toHeroicon(Heroicon::ChartPie),
            'variant' => 'neutral',
        ];
    }

    private function resolvePreviousPeriodComparison(): array
    {
        $period = $this->resolveCurrentPeriod();

        if ($period === null) {
            return [
                'label' => 'Período anterior',
                'value' => '0,0%',
                'footer_label' => 'Comparativo',
                'footer_value' => 'Sem periodo filtrado',
                'icon' => $this->toHeroicon(Heroicon::CalendarDateRange),
                'variant' => 'neutral',
            ];
        }

        [$currentStart, $currentEnd, $format] = $period;
        $days = $currentStart->diffInDays($currentEnd) + 1;
        $previousEnd = $currentStart->copy()->subDay()->endOfDay();
        $previousStart = $previousEnd->copy()->subDays($days - 1)->startOfDay();

        $filters = $this->tableFilters ?? [];
        $invoiceDateFilter = $filters['invoice_date'] ?? [];
        $invoiceDateFilter['invoice_date'] = $this->formatFilterRange($previousStart, $previousEnd, $format);
        $invoiceDateFilter['isActive'] = true;
        $filters['invoice_date'] = $invoiceDateFilter;

        $previousQuery = $this->makeTablePageInstance($filters)->getFilteredSortedTableQuery()->reorder();
        $previousNet = $this->resolveTotals($previousQuery)['net_value'];
        $currentNet = $this->resolveTotals($this->getPageTableQuery()->clone()->reorder())['net_value'];

        $change = $previousNet == 0.0
            ? ($currentNet > 0 ? 100.0 : 0.0)
            : (($currentNet - $previousNet) / $previousNet) * 100;

        $icon = $change > 0
            ? Heroicon::ArrowTrendingUp
            : ($change < 0 ? Heroicon::ArrowTrendingDown : Heroicon::ArrowsRightLeft);

        return [
            'label' => 'Período anterior',
            'value' => $this->formatSignedPercentage($change),
            'footer_label' => 'Base comparativa',
            'footer_value' => sprintf('%s a %s', $previousStart->format('d/m'), $previousEnd->format('d/m')),
            'icon' => $this->toHeroicon($icon),
            'variant' => $change > 0 ? 'success' : ($change < 0 ? 'danger' : 'neutral'),
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: string}|null
     */
    private function resolveCurrentPeriod(): ?array
    {
        $filterState = $this->tableFilters['invoice_date'] ?? $this->getTablePageInstance()->getTableFilterState('invoice_date') ?? null;
        $range = data_get($filterState, 'invoice_date');

        if (! is_string($range) || trim($range) === '') {
            return null;
        }

        $dates = array_map('trim', explode(' - ', $range));

        if (count($dates) !== 2) {
            return null;
        }

        $format = $this->detectFilterDateFormat($dates[0]);

        if ($format === null) {
            return null;
        }

        $start = $this->parseFilterDate($dates[0], $format, false);
        $end = $this->parseFilterDate($dates[1], $format, true);

        if ($start === null || $end === null) {
            return null;
        }

        return [$start, $end, $format];
    }

    private function detectFilterDateFormat(string $value): ?string
    {
        foreach (['d/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d', 'm/d/Y'] as $format) {
            try {
                Carbon::createFromFormat($format, $value);

                return $format;
            } catch (\Throwable) {
            }
        }

        return null;
    }

    private function parseFilterDate(string $value, string $format, bool $endOfDay): ?Carbon
    {
        try {
            $date = Carbon::createFromFormat($format, $value);

            return str_contains($format, 'H:i')
                ? $date
                : ($endOfDay ? $date->endOfDay() : $date->startOfDay());
        } catch (\Throwable) {
            return null;
        }
    }

    private function formatFilterRange(Carbon $start, Carbon $end, string $format): string
    {
        return $start->format($format) . ' - ' . $end->format($format);
    }

    private function makeTablePageInstance(array $tableFilters): HasTable
    {
        /** @var HasTable $page */
        $page = app('livewire')->new($this->getTablePage());

        trigger('mount', $page, [], null, null);

        foreach ([
            'activeTab' => $this->activeTab,
            'paginators' => $this->paginators,
            'parentRecord' => $this->parentRecord,
            'tableColumnSearches' => $this->tableColumnSearches,
            'tableFilters' => $tableFilters,
            'tableGrouping' => $this->tableGrouping,
            'tableRecordsPerPage' => $this->tableRecordsPerPage,
            'tableSearch' => $this->tableSearch,
            'tableSort' => $this->tableSort,
        ] as $property => $value) {
            $page->{$property} = $value;
        }

        $page->bootedInteractsWithTable();

        return $page;
    }

    private function formatMoney(float $amount): string
    {
        return 'R$ ' . number_format($amount, 2, ',', '.');
    }

    private function toPercentage(int|float $value, int|float $total): string
    {
        if ($total <= 0) {
            return '0%';
        }

        return number_format(($value / $total) * 100, 1, ',', '.') . '%';
    }

    private function formatSignedPercentage(float $value): string
    {
        $prefix = $value > 0 ? '+' : '';

        return $prefix . number_format($value, 1, ',', '.') . '%';
    }

    private function toHeroicon(Heroicon $icon): string
    {
        return 'heroicon-m-' . $icon->value;
    }
}
