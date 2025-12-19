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
        Schema::create('requisition_items', function (Blueprint $table) {
            $table->id();                                           // ID único do item
            $table->foreignId('requisition_id')                     // Venda em carteira (obrigatório)
                ->constrained('requisitions')
                ->cascadeOnDelete();
            $table->foreignId('product_id')                         // Produto vendido (obrigatório)
                ->constrained('products');
            $table->string('unit_of_measure');                      // Unidade de medida
            $table->decimal('quantity', 15, 3);                     // Quantidade vendida
            $table->decimal('unit_price', 15, 4);                   // Preço unitário de venda
            $table->decimal('unit_cost', 15, 4)                     // Custo unitário (para margem)
                ->nullable();
            $table->decimal('discount_percentage', 5, 2)            // Desconto em % no item
                ->default(0.00);
            $table->decimal('discount_amount', 15, 2)               // Desconto em valor no item
                ->default(0.00);
            $table->decimal('subtotal', 15, 2)
                ->virtualAs('quantity * unit_price');               // Subtotal (qty × unit_price)
            $table->decimal('total_amount', 15, 2)
                ->virtualAs('subtotal - discount_amount');          // Total final (subtotal - desconto)
            $table->boolean('stock_consumed')                       // Estoque foi baixado?
                ->default(true);
            $table->timestamp('stock_consumed_at')                  // Quando o estoque foi baixado
                ->nullable();
            $table->decimal('commission_percentage', 5, 2)          // Comissão do vendedor (%)
                ->default(0.00)
                ->nullable();
            $table->decimal('commission_amount', 15, 2)             // Valor da comissão
                ->virtualAs('(total_amount * commission_percentage) / 100');
            $table->text('observations')                            // Observações do item
                ->nullable();
            $table->json('additional_info')                         // Informações adicionais (JSON)
                ->nullable();
            $table->foreignId('created_by')                         // Usuário que criou o item
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('updated_by')                         // Usuário que atualizou o item
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();                                   // Data de criação e atualização
            $table->softDeletes();                                  // Data de exclusão (soft delete)

            // Índices para otimizar consultas
            $table->index(['requisition_id', 'product_id']);        // Itens por venda e produto
            $table->index(['product_id', 'stock_consumed']);        // Controle de estoque
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('requisition_items');
    }
};
