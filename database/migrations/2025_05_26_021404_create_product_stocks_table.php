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
        Schema::create('product_stocks', function (Blueprint $table) {
            $table->id();                                           // ID único do registro de estoque
            $table->foreignId('product_id')                         // ID do produto (obrigatório)
                ->constrained('products');
            $table->decimal('quantity_available', 15, 3)            // Quantidade disponível
                ->default(0.000);
            $table->decimal('quantity_reserved', 15, 3)             // Quantidade reservada/bloqueada
                ->default(0.000);
            $table->decimal('quantity_minimum', 15, 3)              // Estoque mínimo
                ->default(0.000);
            $table->decimal('quantity_maximum', 15, 3)              // Estoque máximo
                ->nullable();
            $table->decimal('average_cost', 15, 4)                  // Custo médio unitário
                ->default(0.0000)
                ->nullable();
            $table->decimal('last_cost', 15, 4)                     // Último custo de compra
                ->nullable();
            $table->date('last_movement_date')                      // Data do último movimento
                ->nullable();
            $table->string('last_movement_type')                    // Tipo do último movimento (entrada, saída, ajuste)
                ->nullable();
            $table->boolean('is_active')                            // Registro ativo?
                ->default(true);
            $table->boolean('allow_negative')                       // Permite estoque negativo?
                ->default(false);
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

            // Índices para otimizar consultas
            $table->index('product_id');
            $table->unique('product_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_stocks');
    }
};
