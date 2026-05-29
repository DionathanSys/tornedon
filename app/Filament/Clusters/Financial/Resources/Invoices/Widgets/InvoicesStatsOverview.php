<?php

namespace App\Filament\Clusters\Financial\Resources\Invoices\Widgets;

use App\Enum\Invoice\Status;
use App\Filament\Clusters\Financial\Resources\Invoices\Pages\ListInvoices;
use Filament\Widgets\Concerns\InteractsWithPageTable;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;

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
        $invoiceIdsQuery = $baseQuery->clone()->select('invoices.id');

        $totalInvoices = $baseQuery->clone()->count();
        $pendingInvoices = $baseQuery->clone()->where('status', Status::PENDING->value)->count();
        $confirmedInvoices = $baseQuery->clone()->where('status', Status::CONFIRMED->value)->count();
        $cancelledInvoices = $baseQuery->clone()->where('status', Status::CANCELLED->value)->count();

        $serviceOrdersByInvoice = DB::table('service_orders')
            ->leftJoin('service_order_items', 'service_order_items.service_order_id', '=', 'service_orders.id')
            ->whereIn('service_orders.invoice_id', $invoiceIdsQuery)
            ->groupBy('service_orders.id', 'service_orders.travel_value')
            ->selectRaw('COALESCE(SUM(service_order_items.total_amount), 0) + COALESCE(service_orders.travel_value, 0) as total_amount');

        $requisitionsByInvoice = DB::table('requisitions')
            ->leftJoin('requisition_items', 'requisition_items.requisition_id', '=', 'requisitions.id')
            ->whereIn('requisitions.invoice_id', $invoiceIdsQuery)
            ->groupBy('requisitions.id')
            ->selectRaw('COALESCE(SUM(requisition_items.total_amount), 0) as total_amount');

        $servicesTotal = round(((float) (DB::query()
            ->fromSub($serviceOrdersByInvoice, 'service_order_totals')
            ->selectRaw('COALESCE(SUM(service_order_totals.total_amount), 0) as total_amount')
            ->value('total_amount') ?? 0)) / 100, 2);

        $productsTotal = round(((float) (DB::query()
            ->fromSub($requisitionsByInvoice, 'requisition_totals')
            ->selectRaw('COALESCE(SUM(requisition_totals.total_amount), 0) as total_amount')
            ->value('total_amount') ?? 0)) / 100, 2);

        $netValue = round($servicesTotal + $productsTotal, 2);
        $averageTicket = $totalInvoices > 0 ? round($netValue / $totalInvoices, 2) : 0.0;

        return [
            'summary' => [
                'total_invoices' => $totalInvoices,
                'net_value' => $this->formatMoney($netValue),
                'average_ticket' => $this->formatMoney($averageTicket),
                'services_total' => $this->formatMoney($servicesTotal),
                'products_total' => $this->formatMoney($productsTotal),
                'services_share' => $this->toPercentage($servicesTotal, $netValue),
                'products_share' => $this->toPercentage($productsTotal, $netValue),
                'services_share_width' => $this->toPercentageWidth($servicesTotal, $netValue),
                'products_share_width' => $this->toPercentageWidth($productsTotal, $netValue),
            ],
            'statusCards' => [
                [
                    'label' => 'Pendentes',
                    'value' => $pendingInvoices,
                    'description' => $this->toPercentage($pendingInvoices, $totalInvoices) . ' da listagem',
                    'color' => 'amber',
                ],
                [
                    'label' => 'Confirmadas',
                    'value' => $confirmedInvoices,
                    'description' => $this->toPercentage($confirmedInvoices, $totalInvoices) . ' da listagem',
                    'color' => 'emerald',
                ],
                [
                    'label' => 'Canceladas',
                    'value' => $cancelledInvoices,
                    'description' => $this->toPercentage($cancelledInvoices, $totalInvoices) . ' da listagem',
                    'color' => 'rose',
                ],
            ],
        ];
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

    private function toPercentageWidth(int|float $value, int|float $total): string
    {
        if ($total <= 0) {
            return '0%';
        }

        return round(($value / $total) * 100, 2) . '%';
    }
}
