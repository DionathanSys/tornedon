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
        Schema::create('quotes', function (Blueprint $table) {
            $table->id();                                           // ID único do orçamento
            $table->string('quote_number')                          // Número do orçamento (ex: ORÇ-2026-0001)
                ->nullable();
            $table->foreignId('company_id')                         // Empresa responsável
                ->constrained('companies')
                ->cascadeOnDelete();
            $table->foreignId('customer_id')                         // Cliente que solicitou
                ->constrained('partners');
            $table->text('description')                             // Descrição geral do orçamento
                ->nullable();
            $table->string('status');                               // Status: DRAFT, SENT, APPROVED, REJECTED, EXPIRED
            $table->decimal('total_amount', 15, 2)                  // Valor total do orçamento
                ->default(0.00);
            $table->date('valid_until')                             // Data de validade do orçamento
                ->nullable();
            $table->text('observations')                            // Observações internas
                ->nullable();
            $table->text('customer_observations')                   // Observações do cliente
                ->nullable();
            $table->timestamp('approved_at')                        // Data/hora de aprovação
                ->nullable();
            $table->foreignId('approved_by')                        // Usuário que aprovou
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->text('rejected_reason')                         // Motivo da rejeição
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
            $table->softDeletes();                                  // Data de exclusão (soft delete)

            // Índices para otimizar consultas
            $table->index(['company_id', 'status']);                // Orçamentos por empresa e status
            $table->index(['customer_id', 'status']);               // Orçamentos por cliente
            $table->index(['status', 'valid_until']);               // Orçamentos por status e validade
            $table->index('quote_number');                          // Busca por número
            $table->unique(['company_id', 'quote_number']);         // Número único por empresa
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quotes');
    }
};
