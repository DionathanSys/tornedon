<?php

namespace App\Services\Bpo;

use App\Enum\Invoice\Status;
use App\Models\BpoSale;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderItem;
use App\Models\User;
use App\Services\Access\CompanyAuthorization;
use App\Services\Access\RoleRecordScope;
use Illuminate\Database\Eloquent\Builder;

class SalesReportingService
{
    public function __construct(private CompanyAuthorization $authorization) {}

    public function query(User $user, Company $company): Builder
    {
        app(QuickSaleWorkflow::class)->authorize($user, $company, 'bpo_sales.view');

        // These queries use the explicit actor and tenant, including outside an HTTP session.
        // Applying RoleRecordScope again would repeat authorization for every nested subquery.
        return $this->authorization->scopeQuery(BpoSale::withoutGlobalScope(RoleRecordScope::class), $user, $company, 'bpo_sales.view')
            ->where(fn (Builder $query) => $query
                ->whereIn('service_order_id', $this->orders($user, $company)->select('service_orders.id')->toBase())
                ->orWhereIn('requisition_id', $this->requisitions($user, $company)->select('requisitions.id')->toBase()));
    }

    public function summary(User $user, Company $company, Builder $filteredSales): array
    {
        // Intersect with explicit grants as well as the current table filters/search.
        $sales = $this->query($user, $company)->whereIn('bpo_sales.id', (clone $filteredSales)->reorder()->select('bpo_sales.id')->toBase());
        $orders = $this->orders($user, $company);
        $requisitions = $this->requisitions($user, $company);
        $drafts = $this->totals((clone $sales)->whereNull('finalized_at'), clone $orders, clone $requisitions);
        $result = ['billed_amount' => null, 'billed_count' => null, 'average_amount' => null,
            'draft_count' => $drafts['count'], 'draft_amount' => $drafts['amount']];

        if (! $this->authorization->allows($user, $company, 'invoices.view')) {
            return $result;
        }

        $invoices = $this->authorization->scopeQuery(Invoice::withoutGlobalScope(RoleRecordScope::class), $user, $company, 'invoices.view')
            ->where('confirmed', true)->where('canceled', false)->where('status', '!=', Status::CANCELLED->value);
        $billed = (clone $sales)->whereNotNull('finalized_at')->whereIn('invoice_id', $invoices->select('invoices.id'));
        ['count' => $count, 'amount' => $amount] = $this->totals($billed, $orders, $requisitions);

        return array_replace($result, ['billed_amount' => $amount, 'billed_count' => $count,
            'average_amount' => $count > 0 ? round($amount / $count, 2) : 0.0]);
    }

    private function totals(Builder $sales, Builder $orders, Builder $requisitions): array
    {
        // Items inherit access from their explicitly scoped parent. Aggregate in SQL instead
        // of re-running the entire filtered sales query for each count and monetary component.
        $orders->select('service_orders.id', 'travel_value')
            ->selectSub(ServiceOrderItem::withoutGlobalScope(RoleRecordScope::class)
                ->whereColumn('service_order_id', 'service_orders.id')->selectRaw('COALESCE(SUM(total_amount), 0)'), 'items_amount');
        $requisitions->select('requisitions.id')
            ->selectSub(RequisitionItem::withoutGlobalScope(RoleRecordScope::class)
                ->whereColumn('requisition_id', 'requisitions.id')->selectRaw('COALESCE(SUM(total_amount), 0)'), 'items_amount');
        $totals = $sales->reorder()->toBase()
            ->leftJoinSub($orders->toBase(), 'sale_services', 'sale_services.id', '=', 'bpo_sales.service_order_id')
            ->leftJoinSub($requisitions->toBase(), 'sale_products', 'sale_products.id', '=', 'bpo_sales.requisition_id')
            ->selectRaw('COUNT(*) as sale_count, COALESCE(SUM(COALESCE(sale_services.items_amount, 0) + COALESCE(sale_products.items_amount, 0) + ROUND(COALESCE(sale_services.travel_value, 0) * 100, 0)), 0) as aggregate')
            ->first();

        // Persisted item totals are cents and already include discounts. Travel is in reais.
        return ['count' => (int) $totals->sale_count, 'amount' => round((float) $totals->aggregate / 100, 2)];
    }

    private function orders(User $user, Company $company): Builder
    {
        return $this->authorization->scopeQuery(ServiceOrder::withoutGlobalScope(RoleRecordScope::class), $user, $company, 'service_orders.view');
    }

    private function requisitions(User $user, Company $company): Builder
    {
        return $this->authorization->scopeQuery(Requisition::withoutGlobalScope(RoleRecordScope::class), $user, $company, 'requisitions.view');
    }
}
