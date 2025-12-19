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
        Schema::create('requisitions', function (Blueprint $table) {
            $table->id();                                           // ID único da venda em carteira
            $table->string('number')                                // Número da requisição (único)
                ->unique();
            $table->foreignId('customer_id')                        // Cliente que comprou (obrigatório)
                ->constrained('partners');
            $table->foreignId('company_id')                         // Empresa prestadora
                ->constrained('companies')
                ->cascadeOnDelete();
            $table->foreignId('service_order_id')                     // Ordem de serviço vinculada
                ->nullable()
                ->constrained('service_orders')
                ->nullOnDelete();
            $table->date('sale_date');                              // Data da venda
            $table->string('status');
            $table->decimal('discount_amount', 15, 2)               // Desconto aplicado
                ->default(0.00)
                ->nullable();
            $table->string('payment_method')                        // Forma de pagamento
                ->nullable();
            $table->string('payment_condition')                     // Condição de pagamento (à vista, prazo)
                ->nullable();
            $table->text('observations')                            // Observações da venda
                ->nullable();
            $table->string('delivery_address')                      // Endereço de entrega
                ->nullable();
            $table->date('delivery_date')                           // Data prevista de entrega
                ->nullable();
            $table->foreignId('salesperson_id')                     // Vendedor responsável
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('invoice_id')                         // Nota fiscal gerada (quando faturado)
                ->nullable()
                ->constrained('invoices')
                ->nullOnDelete();
            $table->timestamp('invoiced_at')                        // Data/hora do faturamento
                ->nullable();
            $table->foreignId('equipment_id')                       // Equipamento atendido
                ->nullable()
                ->constrained('equipments')
                ->nullOnDelete();
            $table->boolean('stock_consumed')                       // Estoque já foi consumido?
                ->default(true);
            $table->json('additional_info')                         // Informações adicionais (JSON)
                ->nullable();
            $table->foreignId('created_by')                         // Usuário que criou a venda
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('updated_by')                         // Usuário que atualizou
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();                                   // Data de criação e atualização
            $table->softDeletes();                                  // Data de exclusão (soft delete)

            // Índices para otimizar consultas
            $table->index(['customer_id', 'status']);               // Vendas por cliente e status
            $table->index(['status', 'sale_date']);                 // Vendas por status e período
            $table->index(['company_id', 'status']);                // Vendas por empresa e status
            $table->index(['salesperson_id', 'status']);            // Vendas por vendedor
            $table->index('invoice_id');                            // Relação com nota fiscal
            $table->index(['stock_consumed', 'status']);            // Controle de estoque
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('requisitions');
    }
};
