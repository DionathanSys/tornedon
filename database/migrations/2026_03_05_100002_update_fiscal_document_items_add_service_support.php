<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adiciona service_id (nullable) em fiscal_document_items e
     * torna product_id nullable para suportar itens de serviço (OS).
     * Regra: product_id XOR service_id deve ser preenchido.
     */
    public function up(): void
    {
        Schema::table('fiscal_document_items', function (Blueprint $table) {
            // Torna product_id nullable (antes era required implicitly via FK)
            $table->foreignId('product_id')
                ->nullable()
                ->change();

            // Adiciona service_id para itens de OS
            $table->foreignId('service_id')
                ->nullable()
                ->after('product_id')
                ->constrained('services')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_document_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_id');

            $table->foreignId('product_id')
                ->nullable(false)
                ->change();
        });
    }
};
