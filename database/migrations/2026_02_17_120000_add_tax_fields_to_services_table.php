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
        Schema::table('services', function (Blueprint $table) {
            $table->string('nbs_code')                              // Código NBS (Nomenclatura Brasileira de Serviços)
                ->nullable()
                ->after('tax_rate');
            $table->string('cnae_code')                             // Código CNAE (Classificação Nacional de Atividades Econômicas)
                ->nullable()
                ->after('nbs_code');
            $table->string('municipal_tax_code')                    // Cód. tributação próprio do município
                ->nullable()
                ->after('cnae_code');
            $table->string('iss_exigibility')                       // Exigibilidade do ISS
                ->nullable()
                ->after('municipal_tax_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn([
                'nbs_code',
                'cnae_code',
                'municipal_tax_code',
                'iss_exigibility',
            ]);
        });
    }
};
