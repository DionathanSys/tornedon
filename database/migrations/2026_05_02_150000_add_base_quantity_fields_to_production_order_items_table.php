<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_order_items', function (Blueprint $table) {
            $table->decimal('quantity_in_base_unit', 15, 8)
                ->nullable()
                ->after('quantity');
            $table->decimal('quantity_approved_in_base_unit', 15, 8)
                ->nullable()
                ->after('quantity_approved');
            $table->decimal('conversion_factor_snapshot', 20, 8)
                ->nullable()
                ->after('quantity_approved_in_base_unit');
        });
    }

    public function down(): void
    {
        Schema::table('production_order_items', function (Blueprint $table) {
            $table->dropColumn([
                'quantity_in_base_unit',
                'quantity_approved_in_base_unit',
                'conversion_factor_snapshot',
            ]);
        });
    }
};
