<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('payment_method')->nullable()->after('invoice_date');
            $table->string('payment_condition')->nullable()->after('payment_method');
        });

        DB::table('invoices')
            ->select('id')
            ->orderBy('id')
            ->chunkById(100, function ($invoices): void {
                foreach ($invoices as $invoice) {
                    $firstServiceOrder = DB::table('service_orders')
                        ->select('payment_method', 'payment_condition')
                        ->where('invoice_id', $invoice->id)
                        ->orderBy('id')
                        ->first();

                    if (! $firstServiceOrder) {
                        continue;
                    }

                    DB::table('invoices')
                        ->where('id', $invoice->id)
                        ->update([
                            'payment_method' => $firstServiceOrder->payment_method,
                            'payment_condition' => $firstServiceOrder->payment_condition,
                        ]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn([
                'payment_method',
                'payment_condition',
            ]);
        });
    }
};
