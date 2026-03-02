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
        Schema::create('production_orders', function (Blueprint $table) {
            $table->id();                                           // ID único da ordem de produção
            $table->string('production_order_number')               // Número da ordem (ex: PRD-2026-0001)
                ->nullable();
            $table->foreignId('company_id')                         // Empresa responsável
                ->constrained('companies')
                ->cascadeOnDelete();
            $table->foreignId('quote_id')                           // Orçamento relacionado (opcional)
                ->nullable()
                ->constrained('quotes');
            $table->foreignId('customer_id')                         // Cliente da produção
                ->constrained('partners');
            $table->string('status');                               // Status: QUEUED, IN_PROGRESS, QC_CHECK, COMPLETED, CANCELLED
            $table->string('priority')                              // Prioridade: LOW, NORMAL, HIGH, URGENT
                ->default('NORMAL');
            $table->timestamp('started_at')                         // Data/hora de início da produção
                ->nullable();
            $table->timestamp('completed_at')                       // Data/hora de conclusão
                ->nullable();
            $table->string('destination_type');                     // STOCK (estoque) ou DIRECT_DELIVERY (entrega direta)
            $table->text('observations')                            // Observações gerais
                ->nullable();
            $table->foreignId('assigned_operator')                  // Operador responsável
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('assigned_machine')                      // Máquina/equipamento atribuído
                ->nullable();
            $table->decimal('total_estimated_hours', 10, 2)         // Total de horas estimadas
                ->nullable();
            $table->decimal('total_actual_hours', 10, 2)            // Total de horas reais
                ->nullable();
            $table->json('additional_info')                         // Informações adicionais (JSON)
                ->nullable();
            $table->foreignId('created_by')                         // Usuário que criou
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('updated_by')                         // Usuário que atualizou
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();                                   // Data de criação e atualização

            // Índices para otimizar consultas
            $table->index(['company_id', 'status']);                // Ordens por empresa e status
            $table->index(['customer_id', 'status']);                // Ordens por cliente
            $table->index(['status', 'priority']);                  // Por status e prioridade
            $table->index('quote_id');                              // Relação com orçamento
            $table->index(['destination_type', 'status']);          // Por destino e status
            $table->index('production_order_number');               // Busca por número
            $table->index('assigned_operator');                     // Por operador
            $table->unique(['company_id', 'production_order_number']); // Número único por empresa
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('production_orders');
    }
};
