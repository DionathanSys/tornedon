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
        Schema::table('invoices', function (Blueprint $table) {
            // Remove constraint única simples
            $table->dropUnique(['invoice_number']);
            
            // Criar índice único composto para suportar multi-tenant
            $table->unique(['company_id', 'invoice_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // Remove constraint única composta
            $table->dropUnique(['company_id', 'invoice_number']);
            
            // Restaurar constraint única simples
            $table->unique(['invoice_number']);
        });
    }
};
