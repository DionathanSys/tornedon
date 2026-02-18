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
        Schema::table('products', function (Blueprint $table) {
            $table->string('origin_sale_price', 20)
                ->nullable()
                ->after('min_sale_price');

            $table->decimal('sale_price_value', 12, 2)
                ->nullable()
                ->after('origin_sale_price');

            $table->json('external_reference_codes')
                ->nullable()
                ->after('sale_price_value');

            $table->string('item_type', 60)
                ->nullable()
                ->after('external_reference_codes');

            $table->string('manufacturer_code', 100)
                ->nullable()
                ->after('item_type');

            $table->decimal('gross_weight', 12, 3)
                ->nullable()
                ->after('manufacturer_code');

            $table->decimal('net_weight', 12, 3)
                ->nullable()
                ->after('gross_weight');

            $table->string('barcode', 60)
                ->nullable()
                ->after('net_weight');

            $table->boolean('is_invoiceable')
                ->default(false)
                ->after('barcode');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'origin_sale_price',
                'sale_price_value',
                'external_reference_codes',
                'item_type',
                'manufacturer_code',
                'gross_weight',
                'net_weight',
                'barcode',
                'is_invoiceable',
            ]);
        });
    }
};
