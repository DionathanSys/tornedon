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
        Schema::create('fiscal_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('fiscal_profile_id')->constrained('fiscal_profiles')->cascadeOnDelete();
            
            // Chaves de matching (dimensões)
            $table->string('operation_nature', 100);
            $table->string('tax_regime', 30);
            $table->boolean('is_interestadual')->default(false);
            $table->string('product_origin', 5)->nullable();
            $table->boolean('has_st')->nullable();
            $table->string('ncm_prefix', 8)->nullable();
            $table->string('recipient_type', 20)->nullable();
            $table->boolean('is_final_consumer')->nullable();
            
            // Resultado fiscal
            $table->string('cfop', 4);
            $table->string('cst_icms', 3)->nullable();
            $table->string('csosn', 3)->nullable();
            $table->string('cst_pis', 2)->nullable();
            $table->string('cst_cofins', 2)->nullable();
            $table->string('cst_ipi', 3)->nullable();
            
            // Alíquotas override (NULL = usar default do profile)
            $table->decimal('aliquota_icms', 8, 4)->nullable();
            $table->decimal('aliquota_pis', 8, 4)->nullable();
            $table->decimal('aliquota_cofins', 8, 4)->nullable();
            $table->decimal('aliquota_ipi', 8, 4)->nullable();
            
            // Controle
            $table->integer('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('description', 255)->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            
            $table->timestamps();
            
            $table->index(['company_id', 'operation_nature', 'tax_regime', 'is_active'], 'idx_fiscal_rules_lookup');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fiscal_rules');
    }
};
