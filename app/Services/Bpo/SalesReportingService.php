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
use Illuminate\Database\Eloquent\Builder;

class SalesReportingService
{
    public function __construct(private CompanyAuthorization $authorization) {}

    public function query(User $user, Company $company): Builder
    {
        app(QuickSaleWorkflow::class)->authorize($user, $company, 'bpo_sales.view');

        return $this->authorization->scopeQuery(BpoSale::query(), $user, $company, 'bpo_sales.view')
            ->where(fn (Builder $query) => $query
                ->whereIn('service_order_id', $this->orders($user, $company)->select('service_orders.id'))
                ->orWhereIn('requisition_id', $this->requisitions($user, $company)->select('requisitions.id')));
    }

    public function summary(User $user, Company $company, Builder $filteredSales): array
    {
        // Intersect with explicit grants as well as the current table filters/search.
        $sales = $this->query($user, $company)->whereIn('bpo_sales.id', (clone $filteredSales)->reorder()->select('bpo_sales.id'));
        $drafts = (clone $sales)->whereNull('finalized_at');
        $result = ['billed_amount' => null, 'billed_count' => null, 'average_amount' => null,
            'draft_count' => (clone $drafts)->count(), 'draft_amount' => $this->amount($user, $company, $drafts)];

        if (! $this->authorization->allows($user, $company, 'invoices.view')) {
            return $result;
        }

        $invoices = $this->authorization->scopeQuery(Invoice::query(), $user, $company, 'invoices.view')
            ->where('confirmed', true)->where('canceled', false)->where('status', '!=', Status::CANCELLED->value);
        $billed = (clone $sales)->whereNotNull('finalized_at')->whereIn('invoice_id', $invoices->select('invoices.id'));
        $count = (clone $billed)->count();
        $amount = $this->amount($user, $company, $billed);

        return array_replace($result, ['billed_amount' => $amount, 'billed_count' => $count,
            'average_amount' => $count > 0 ? round($amount / $count, 2) : 0.0]);
    }

    private function amount(User $user, Company $company, Builder $sales): float
    {
        $orders = $this->orders($user, $company)->whereIn('service_orders.id', (clone $sales)->reorder()->select('service_order_id'));
        $requisitions = $this->requisitions($user, $company)->whereIn('requisitions.id', (clone $sales)->reorder()->select('requisition_id'));
        // Persisted item totals are cents and already include discounts. Travel is in reais.
        $services = (int) ServiceOrderItem::query()->whereIn('service_order_id', (clone $orders)->select('service_orders.id'))->sum('total_amount');
        $travel = (int) round((float) (clone $orders)->sum('travel_value') * 100);
        $products = (int) RequisitionItem::query()->whereIn('requisition_id', $requisitions->select('requisitions.id'))->sum('total_amount');

        return ($services + $travel + $products) / 100;
    }

    private function orders(User $user, Company $company): Builder
    {
        return $this->authorization->scopeQuery(ServiceOrder::query(), $user, $company, 'service_orders.view');
    }

    private function requisitions(User $user, Company $company): Builder
    {
        return $this->authorization->scopeQuery(Requisition::query(), $user, $company, 'requisitions.view');
    }
}
