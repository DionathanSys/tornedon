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
            $table->dropUnique(['product_code']);
            $table->unique(['company_id', 'product_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Criar índice em company_id antes de dropar o composto,
        // pois a FK de company_id depende de um índice.
        Schema::table('products', function (Blueprint $table) {
            $table->index('company_id');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'product_code']);
            $table->unique(['product_code']);
        });
    }
};
