<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                ->unique()
                ->constrained('companies')
                ->cascadeOnDelete();
            $table->string('tax_regime', 30);
            $table->string('cnae_principal', 10)->nullable();

            // ICMS padrao
            $table->string('icms_cst_default', 3)->nullable();
            $table->string('icms_csosn_default', 4)->nullable();
            $table->decimal('icms_aliquota_interna', 5, 2)->nullable();
            $table->decimal('icms_reducao_base', 5, 2)->nullable();
            $table->string('icms_modalidade_base_calculo', 1)->nullable();

            // ICMS ST
            $table->decimal('icms_st_aliquota', 5, 2)->nullable();
            $table->decimal('icms_st_mva', 5, 2)->nullable();
            $table->decimal('icms_st_reducao_base', 5, 2)->nullable();

            // Aliquotas interestaduais
            $table->json('icms_aliquotas_interestaduais')->nullable();

            // PIS
            $table->string('pis_cst_default', 3)->nullable();
            $table->decimal('pis_aliquota_default', 5, 4)->nullable();

            // COFINS
            $table->string('cofins_cst_default', 3)->nullable();
            $table->decimal('cofins_aliquota_default', 5, 4)->nullable();

            // IPI
            $table->string('ipi_cst_default', 3)->nullable();
            $table->decimal('ipi_aliquota_default', 5, 2)->nullable();
            $table->string('ipi_enquadramento', 10)->nullable();

            // CFOP rules e informacoes adicionais
            $table->json('cfop_rules')->nullable();
            $table->text('additional_tax_information_default')->nullable();
            $table->text('additional_taxpayer_information_default')->nullable();
            $table->json('additional_purchase_information_default')->nullable();
            $table->json('taxpayer_observations_default')->nullable();
            $table->json('tax_observations_default')->nullable();
            $table->text('informacoes_complementares_padrao')->nullable();

            // Hash de integridade
            $table->string('ruleset_checksum', 64)->nullable();

            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_profiles');
    }
};
