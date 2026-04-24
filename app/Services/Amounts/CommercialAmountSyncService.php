<?php

namespace App\Services\Amounts;

use App\Models\Quote;
use App\Models\Requisition;
use App\Models\ServiceOrder;
use Illuminate\Support\Facades\DB;

class CommercialAmountSyncService
{
    public function syncQuote(int|Quote $quote): void
    {
        $quoteId = $quote instanceof Quote ? $quote->id : $quote;

        $totals = DB::table('quote_items')
            ->where('quote_id', $quoteId)
            ->selectRaw('
                COALESCE(SUM(gross_amount), 0) as gross_amount,
                COALESCE(SUM(discount_amount), 0) as discount_amount,
                COALESCE(SUM(total_amount), 0) as total_amount
            ')
            ->first();

        DB::table('quotes')
            ->where('id', $quoteId)
            ->update([
                'gross_amount' => round((float) ($totals->gross_amount ?? 0), 2),
                'discount_amount' => round((float) ($totals->discount_amount ?? 0), 2),
                'total_amount' => round((float) ($totals->total_amount ?? 0), 2),
            ]);
    }

    public function syncRequisition(int|Requisition $requisition): void
    {
        $requisitionId = $requisition instanceof Requisition ? $requisition->id : $requisition;

        $totals = DB::table('requisition_items')
            ->where('requisition_id', $requisitionId)
            ->selectRaw('
                COALESCE(SUM(gross_amount), 0) as gross_amount,
                COALESCE(SUM(discount_amount), 0) as discount_amount,
                COALESCE(SUM(total_amount), 0) as total_amount
            ')
            ->first();

        DB::table('requisitions')
            ->where('id', $requisitionId)
            ->update([
                'gross_amount' => round((float) ($totals->gross_amount ?? 0), 2),
                'discount_amount' => round((float) ($totals->discount_amount ?? 0), 2),
                'total_amount' => round((float) ($totals->total_amount ?? 0), 2),
            ]);
    }

    public function syncServiceOrder(int|ServiceOrder $serviceOrder): void
    {
        $serviceOrderId = $serviceOrder instanceof ServiceOrder ? $serviceOrder->id : $serviceOrder;

        $order = DB::table('service_orders')
            ->where('id', $serviceOrderId)
            ->select('travel_value')
            ->first();

        if ($order === null) {
            return;
        }

        $totals = DB::table('service_order_items')
            ->where('service_order_id', $serviceOrderId)
            ->selectRaw('
                COALESCE(SUM(gross_amount), 0) as gross_amount,
                COALESCE(SUM(discount_amount), 0) as discount_amount,
                COALESCE(SUM(total_amount), 0) as total_amount
            ')
            ->first();

        $travelValue = (float) ($order->travel_value ?? 0);

        DB::table('service_orders')
            ->where('id', $serviceOrderId)
            ->update([
                'gross_amount' => round((float) ($totals->gross_amount ?? 0) + $travelValue, 2),
                'discount_amount' => round((float) ($totals->discount_amount ?? 0), 2),
                'total_amount' => round((float) ($totals->total_amount ?? 0) + $travelValue, 2),
            ]);
    }
}
