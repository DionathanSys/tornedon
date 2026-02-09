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
        Schema::create('production_order_items', function (Blueprint $table) {
            $table->id();                                           // ID único do item
            $table->foreignId('production_order_id')                // Ordem de produção
                ->constrained('production_orders')
                ->cascadeOnDelete();
            $table->foreignId('quote_item_id')                      // Item do orçamento (se criado a partir de orçamento)
                ->nullable()
                ->constrained('quote_items')
                ->nullOnDelete();
            $table->foreignId('product_id')                         // Produto relacionado (pode ser null para peças customizadas)
                ->nullable()
                ->constrained('products')
                ->nullOnDelete();
            $table->text('description');                            // Descrição do item/peça
            $table->decimal('quantity', 15, 3)                      // Quantidade solicitada
                ->default(1.000);
            $table->decimal('quantity_produced', 15, 3)             // Quantidade produzida
                ->default(0.000);
            $table->decimal('quantity_approved', 15, 3)             // Quantidade aprovada no controle de qualidade
                ->default(0.000);
            $table->decimal('quantity_rejected', 15, 3)             // Quantidade rejeitada/com defeito
                ->default(0.000);
            $table->string('unit_of_measure')                       // Unidade de medida
                ->default('UN');
            $table->json('technical_specifications')                // Especificações técnicas
                ->nullable();
            $table->text('production_notes')                        // Notas de produção
                ->nullable();
            $table->text('qc_notes')                                // Notas do controle de qualidade
                ->nullable();
            $table->decimal('actual_production_hours', 10, 2)       // Horas reais de produção
                ->nullable();
            $table->integer('sequence')                             // Ordem de produção
                ->default(0);
            $table->json('additional_info')                         // Informações adicionais (JSON)
                ->nullable();
            $table->timestamps();                                   // Data de criação e atualização

            // Índices para otimizar consultas
            $table->index('production_order_id');                   // Itens por ordem de produção
            $table->index('quote_item_id');                         // Relação com item do orçamento
            $table->index('product_id');                            // Relação com produto
            $table->index(['production_order_id', 'sequence']);     // Ordenação dos itens
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('production_order_items');
    }
};
