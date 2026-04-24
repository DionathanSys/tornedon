<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            $table->decimal('gross_amount', 15, 2)
                ->default(0)
                ->after('travel_value');
            $table->decimal('discount_amount', 15, 2)
                ->default(0)
                ->after('gross_amount');
            $table->decimal('total_amount', 15, 2)
                ->default(0)
                ->after('discount_amount');
        });

        Schema::table('requisitions', function (Blueprint $table) {
            $table->decimal('gross_amount', 15, 2)
                ->default(0)
                ->after('status');
            $table->decimal('discount_amount', 15, 2)
                ->default(0)
                ->after('gross_amount');
            $table->decimal('total_amount', 15, 2)
                ->default(0)
                ->after('discount_amount');
        });

        Schema::table('quotes', function (Blueprint $table) {
            $table->decimal('gross_amount', 15, 2)
                ->default(0)
                ->after('status');
            $table->decimal('discount_amount', 15, 2)
                ->default(0)
                ->after('gross_amount');
            $table->decimal('total_amount', 15, 2)
                ->default(0)
                ->after('discount_amount');
        });

        $this->backfillQuotes();
        $this->backfillServiceOrders();
        $this->backfillRequisitions();
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropColumn(['gross_amount', 'discount_amount', 'total_amount']);
        });

        Schema::table('requisitions', function (Blueprint $table) {
            $table->dropColumn(['gross_amount', 'discount_amount', 'total_amount']);
        });

        Schema::table('service_orders', function (Blueprint $table) {
            $table->dropColumn(['gross_amount', 'discount_amount', 'total_amount']);
        });
    }

    private function backfillQuotes(): void
    {
        DB::table('quotes')->orderBy('id')->chunkById(100, function ($quotes): void {
            foreach ($quotes as $quote) {
                $totals = DB::table('quote_items')
                    ->where('quote_id', $quote->id)
                    ->selectRaw('
                        COALESCE(SUM(gross_amount), 0) as gross_amount,
                        COALESCE(SUM(discount_amount), 0) as discount_amount,
                        COALESCE(SUM(total_amount), 0) as total_amount
                    ')
                    ->first();

                DB::table('quotes')
                    ->where('id', $quote->id)
                    ->update([
                        'gross_amount' => round((float) ($totals->gross_amount ?? 0), 2),
                        'discount_amount' => round((float) ($totals->discount_amount ?? 0), 2),
                        'total_amount' => round((float) ($totals->total_amount ?? 0), 2),
                    ]);
            }
        });
    }

    private function backfillServiceOrders(): void
    {
        DB::table('service_orders')->orderBy('id')->chunkById(100, function ($serviceOrders): void {
            foreach ($serviceOrders as $serviceOrder) {
                $totals = DB::table('service_order_items')
                    ->where('service_order_id', $serviceOrder->id)
                    ->selectRaw('
                        COALESCE(SUM(gross_amount), 0) as gross_amount,
                        COALESCE(SUM(discount_amount), 0) as discount_amount,
                        COALESCE(SUM(total_amount), 0) as total_amount
                    ')
                    ->first();

                $travelValue = (float) ($serviceOrder->travel_value ?? 0);

                DB::table('service_orders')
                    ->where('id', $serviceOrder->id)
                    ->update([
                        'gross_amount' => round((float) ($totals->gross_amount ?? 0) + $travelValue, 2),
                        'discount_amount' => round((float) ($totals->discount_amount ?? 0), 2),
                        'total_amount' => round((float) ($totals->total_amount ?? 0) + $travelValue, 2),
                    ]);
            }
        });
    }

    private function backfillRequisitions(): void
    {
        DB::table('requisitions')->orderBy('id')->chunkById(100, function ($requisitions): void {
            foreach ($requisitions as $requisition) {
                $totals = DB::table('requisition_items')
                    ->where('requisition_id', $requisition->id)
                    ->selectRaw('
                        COALESCE(SUM(gross_amount), 0) as gross_amount,
                        COALESCE(SUM(discount_amount), 0) as discount_amount,
                        COALESCE(SUM(total_amount), 0) as total_amount
                    ')
                    ->first();

                DB::table('requisitions')
                    ->where('id', $requisition->id)
                    ->update([
                        'gross_amount' => round((float) ($totals->gross_amount ?? 0), 2),
                        'discount_amount' => round((float) ($totals->discount_amount ?? 0), 2),
                        'total_amount' => round((float) ($totals->total_amount ?? 0), 2),
                    ]);
            }
        });
    }
};
