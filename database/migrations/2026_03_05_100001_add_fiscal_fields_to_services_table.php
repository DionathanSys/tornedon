<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adiciona campos fiscais de NF-e à tabela de serviços.
     * Necessário para mapear ServiceOrderItems → FiscalDocumentItems.
     */
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->string('ncm_code', 10)
                ->nullable()
                ->after('cnae_code')
                ->comment('Código NCM (usado quando serviço aparece em NF-e)');
            $table->string('cfop_code', 5)
                ->nullable()
                ->after('ncm_code')
                ->comment('CFOP de saída (ex: 5933 — outras saídas sem ICMS)');
            $table->string('origin_code', 2)
                ->nullable()
                ->default('07')
                ->after('cfop_code')
                ->comment('Origem (07 = serviço adquirido no exterior por padrão)');
            $table->string('unit_of_measure', 10)
                ->nullable()
                ->default('UN')
                ->after('origin_code')
                ->comment('Unidade de medida padrão para o item fiscal');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn([
                'ncm_code',
                'cfop_code',
                'origin_code',
                'unit_of_measure',
            ]);
        });
    }
};
