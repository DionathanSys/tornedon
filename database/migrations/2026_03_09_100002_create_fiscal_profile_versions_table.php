<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_profile_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_profile_id')
                ->constrained('fiscal_profiles')
                ->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->string('status', 20)->default('active'); // active, draft, archived

            // ICMS padrão
            $table->string('icms_cst_default', 3)->nullable();
            $table->string('icms_csosn_default', 4)->nullable();
            $table->decimal('icms_aliquota_interna', 5, 2)->nullable();
            $table->decimal('icms_reducao_base', 5, 2)->nullable();
            $table->string('icms_modalidade_base_calculo', 1)->nullable(); // 0-3

            // ICMS ST
            $table->decimal('icms_st_aliquota', 5, 2)->nullable();
            $table->decimal('icms_st_mva', 5, 2)->nullable();
            $table->decimal('icms_st_reducao_base', 5, 2)->nullable();

            // Alíquotas interestaduais
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

            // CFOP rules
            $table->json('cfop_rules')->nullable();

            // Info complementar
            $table->text('informacoes_complementares_padrao')->nullable();

            // Hash de integridade
            $table->string('ruleset_checksum', 64)->nullable();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();

            $table->unique(['fiscal_profile_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_profile_versions');
    }
};
