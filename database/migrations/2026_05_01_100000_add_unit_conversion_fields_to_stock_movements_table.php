<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropColumn('total_amount');
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->string('operational_unit', 6)
                ->nullable()
                ->after('type');
            $table->decimal('operational_quantity', 12, 3)
                ->nullable()
                ->after('operational_unit');
            $table->string('base_unit', 6)
                ->nullable()
                ->after('operational_quantity');
            $table->decimal('base_quantity', 12, 3)
                ->nullable()
                ->after('base_unit');
            $table->decimal('conversion_factor_snapshot', 20, 8)
                ->nullable()
                ->after('base_quantity');
            $table->decimal('total_amount', 15, 2)
                ->virtualAs('coalesce(operational_quantity, quantity) * unit_price')
                ->after('unit_price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropColumn([
                'total_amount',
                'operational_unit',
                'operational_quantity',
                'base_unit',
                'base_quantity',
                'conversion_factor_snapshot',
            ]);
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->decimal('total_amount', 15, 2)
                ->virtualAs('quantity * unit_price')
                ->after('unit_price');
        });
    }
};
