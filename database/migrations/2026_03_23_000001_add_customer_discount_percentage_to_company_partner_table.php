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
        Schema::table('company_partner', function (Blueprint $table) {
            $table->decimal('customer_discount_percentage', 5, 2)
                ->nullable()
                ->after('invoice_threshold');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_partner', function (Blueprint $table) {
            $table->dropColumn('customer_discount_percentage');
        });
    }
};
