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
        Schema::create('services', function (Blueprint $table) {
            $table->id();                                           // ID único do serviço
            $table->string('name');                                 // Nome/descrição do serviço
            $table->text('description')                             // Descrição detalhada do serviço
                ->nullable();
            $table->string('unit_of_measure');                      // Unidade de medida (hora, peça, m², etc.)
            $table->decimal('price', 15, 2)                         // Preço base do serviço
                ->default(0.00);
            $table->decimal('cost', 15, 2)                          // Custo do serviço
                ->default(0.00)
                ->nullable();
            $table->string('category')                              // Categoria do serviço
                ->nullable();
            $table->boolean('is_active')                            // Serviço ativo?
                ->default(true);
            $table->boolean('requires_approval')                    // Requer aprovação?
                ->default(false);
            $table->string('tax_classification')                    // Classificação fiscal (código de serviço municipal)
                ->nullable();
            $table->decimal('tax_rate', 5, 2)                       // Alíquota de imposto (%)
                ->default(0.00)
                ->nullable();
            $table->json('additional_info')                         // Informações adicionais (JSON)
                ->nullable();
            $table->foreignId('created_by')                         // Usuário que criou o registro
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('updated_by')                         // Usuário que atualizou o registro
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();                                   // Data de criação e atualização
            $table->softDeletes();                                  // Data de exclusão (soft delete)
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
