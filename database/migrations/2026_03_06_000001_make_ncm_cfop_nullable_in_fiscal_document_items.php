<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Torna ncm_code e cfop_code nullable em fiscal_document_items.
     *
     * Produtos e serviços existentes podem não ter esses campos preenchidos.
     * A validação se dá na camada de domínio antes da emissão da NF-e,
     * não na constraint do banco.
     */
    public function up(): void
    {
        Schema::table('fiscal_document_items', function (Blueprint $table) {
            $table->string('ncm_code', 10)->nullable()->change();
            $table->string('cfop_code', 5)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_document_items', function (Blueprint $table) {
            $table->string('ncm_code', 8)->nullable(false)->change();
            $table->string('cfop_code', 4)->nullable(false)->change();
        });
    }
};
