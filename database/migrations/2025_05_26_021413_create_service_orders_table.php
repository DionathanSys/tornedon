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
        Schema::create('service_orders', function (Blueprint $table) {
            $table->id();                                           // ID único da ordem de serviço
            $table->string('number')                                // Número da OS (único)
                ->unique();
            $table->foreignId('customer_id')                        // Cliente solicitante (obrigatório)
                ->constrained('partners');
            $table->foreignId('company_id')                         // Empresa prestadora
                ->constrained('companies')
                ->cascadeOnDelete();
            $table->date('order_date');                             // Data da abertura da OS
            $table->date('scheduled_date')                          // Data agendada para execução
                ->nullable();
            $table->date('limit_date')                              // Data limite para entrega
                ->nullable();
            $table->date('completion_date')                         // Data de conclusão
                ->nullable();
            $table->string('status');
            $table->string('priority');
            $table->string('type');                                 // Tipo de serviço (instalação, manutenção, reparo, etc.)
            $table->text('solution')                                // Solução aplicada/procedimentos realizados
                ->nullable();
            $table->foreignId('equipment_id')                       // Equipamento atendido
                ->nullable()
                ->constrained('equipments')
                ->nullOnDelete();
            $table->string('location')                              // Local de atendimento
                ->nullable();
            $table->text('customer_observations')                   // Observações do cliente
                ->nullable();
            $table->text('technician_observations')                 // Observações do técnico
                ->nullable();
            $table->decimal('estimated_hours', 8, 2)                // Horas estimadas
                ->default(0.00)
                ->nullable();
            $table->decimal('actual_hours', 8, 2)                   // Horas reais trabalhadas
                ->default(0.00)
                ->nullable();
            $table->decimal('travel_value', 15, 2)                  // Valor de deslocamento
                ->default(0.00);
            $table->decimal('discount_amount', 15, 2)               // Desconto aplicado
                ->default(0.00);
            $table->string('payment_method')                        // Forma de pagamento
                ->nullable();
            $table->string('payment_condition')                     // Condição de pagamento (à vista, prazo)
                ->nullable();
            $table->foreignId('technician_id')                      // Técnico responsável
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('supervisor_id')                      // Supervisor responsável
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('salesperson_id')                     // Vendedor que captou o serviço
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->date('warranty_expires_at')                     // Data de expiração da garantia
                ->nullable();
            $table->boolean('requires_approval')                    // Requer aprovação do cliente?
                ->default(false);
            $table->boolean('approved_by_customer')                 // Aprovado pelo cliente?
                ->default(false);
            $table->timestamp('approved_at')                        // Data/hora da aprovação
                ->nullable();
            $table->decimal('customer_rating', 2, 1)                // Avaliação do cliente (0-5)
                ->nullable();
            $table->text('customer_feedback')                       // Feedback do cliente
                ->nullable();
            $table->foreignId('invoice_id')                         // Fatura gerada
                ->nullable()
                ->constrained('invoices')
                ->nullOnDelete();
            $table->json('additional_info')                         // Informações adicionais (JSON)
                ->nullable();
            $table->foreignId('created_by')                         // Usuário que criou a OS
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('updated_by')                         // Usuário que atualizou a OS
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();                                   // Data de criação e atualização
            $table->softDeletes();                                  // Data de exclusão (soft delete)

            // Índices para otimizar consultas
            $table->index(['customer_id', 'status']);               // OS por cliente e status
            $table->index(['company_id', 'status']);                // OS por empresa e status
            $table->index(['technician_id', 'status']);             // OS por técnico
            $table->index(['status', 'priority', 'order_date']);    // Fila de atendimento
            $table->index(['type', 'status']);                      // OS por tipo de serviço
            $table->index('equipment_id');                          // Histórico por equipamento
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_orders');
    }
};
