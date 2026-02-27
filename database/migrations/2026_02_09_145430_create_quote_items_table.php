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
        Schema::create('quote_items', function (Blueprint $table) {
            $table->id();                                           // ID único do item
            $table->foreignId('quote_id')                           // Orçamento ao qual pertence
                ->constrained('quotes')
                ->cascadeOnDelete();
            $table->foreignId('product_id')                         // Produto relacionado (pode ser null para peças customizadas)
                ->nullable()
                ->constrained('products')
                ->nullOnDelete();
            $table->text('description')                             // Descrição do item/peça
                ->nullable();                            
            $table->decimal('quantity', 15, 3)                      // Quantidade
                ->default(1.000);
            $table->string('unit_of_measure')                       // Unidade de medida (PC, UN, KG, etc)
                ->default('UN')
                ->nullable();
            $table->decimal('unit_price', 15, 4)                    // Preço unitário
                ->default(0.0000);
            $table->decimal('discount_percentage', 5, 3)            // Percentual de desconto
                ->default(0.000);
            $table->decimal('discount_amount', 15, 2)               // Valor do desconto
                ->default(0.00);
            $table->decimal('total_amount', 15, 2)                  // Valor total do item
                ->default(0.00);
            $table->json('technical_specifications')                // Especificações técnicas (dimensões, tolerâncias, material, operações)
                ->nullable();
            $table->decimal('estimated_production_hours', 10, 2)    // Horas estimadas de produção
                ->nullable();
            $table->decimal('material_cost', 15, 2)                 // Custo do material
                ->nullable();
            $table->decimal('labor_cost', 15, 2)                    // Custo da mão de obra
                ->nullable();
            $table->integer('sequence')                             // Ordem de exibição
                ->default(0);
            $table->json('additional_info')                         // Informações adicionais (JSON)
                ->nullable();
            $table->timestamps();                                   // Data de criação e atualização

            // Índices para otimizar consultas
            $table->index('quote_id');                              // Itens por orçamento
            $table->index('product_id');                            // Relação com produto
            $table->index(['quote_id', 'sequence']);                // Ordenação dos itens
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quote_items');
    }
};
