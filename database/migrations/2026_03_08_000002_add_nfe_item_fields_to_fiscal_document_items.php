<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_document_items', function (Blueprint $table) {
            $table->string('description', 255)->nullable()->after('product_code');
            $table->string('barcode', 60)->nullable()->after('ncm_code');
            $table->string('cest_code', 9)->nullable()->after('ncm_code');
            $table->string('taxable_unit', 6)->nullable()->after('unit_of_measure');
            $table->decimal('taxable_quantity', 15, 4)->nullable()->after('taxable_unit');
            $table->decimal('taxable_unit_price', 15, 4)->nullable()->after('taxable_quantity');
            $table->decimal('discount_amount', 15, 2)->default(0)->after('total_price');
            $table->decimal('freight_amount', 15, 2)->default(0)->after('discount_amount');
            $table->decimal('insurance_amount', 15, 2)->default(0)->after('freight_amount');
            $table->decimal('other_expenses_amount', 15, 2)->default(0)->after('insurance_amount');
            $table->text('additional_information')->nullable()->after('tax_data');
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_document_items', function (Blueprint $table) {
            $table->dropColumn([
                'description',
                'barcode',
                'cest_code',
                'taxable_unit',
                'taxable_quantity',
                'taxable_unit_price',
                'discount_amount',
                'freight_amount',
                'insurance_amount',
                'other_expenses_amount',
                'additional_information',
            ]);
        });
    }
};
