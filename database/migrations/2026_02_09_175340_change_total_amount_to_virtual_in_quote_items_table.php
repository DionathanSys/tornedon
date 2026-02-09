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
        Schema::table('quote_items', function (Blueprint $table) {
            // Remove a coluna total_amount existente
            $table->dropColumn('total_amount');
        });

        // Adiciona como coluna virtual
        Schema::table('quote_items', function (Blueprint $table) {
            $table->decimal('total_amount', 15, 2)
                ->virtualAs('(quantity * unit_price) - discount_amount')
                ->after('discount_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quote_items', function (Blueprint $table) {
            $table->dropColumn('total_amount');
        });

        Schema::table('quote_items', function (Blueprint $table) {
            $table->decimal('total_amount', 15, 2)
                ->default(0.00)
                ->after('discount_amount');
        });
    }
};
