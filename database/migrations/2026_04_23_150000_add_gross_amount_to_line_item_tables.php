<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_order_items', function (Blueprint $table) {
            $table->decimal('gross_amount', 15, 2)
                ->virtualAs('quantity * unit_price')
                ->after('discount_amount');
        });

        Schema::table('requisition_items', function (Blueprint $table) {
            $table->decimal('gross_amount', 15, 2)
                ->virtualAs('quantity * unit_price')
                ->after('discount_amount');
        });

        Schema::table('quote_items', function (Blueprint $table) {
            $table->decimal('gross_amount', 15, 2)
                ->virtualAs('quantity * unit_price')
                ->after('discount_amount');
        });
    }

    public function down(): void
    {
        Schema::table('quote_items', function (Blueprint $table) {
            $table->dropColumn('gross_amount');
        });

        Schema::table('requisition_items', function (Blueprint $table) {
            $table->dropColumn('gross_amount');
        });

        Schema::table('service_order_items', function (Blueprint $table) {
            $table->dropColumn('gross_amount');
        });
    }
};
