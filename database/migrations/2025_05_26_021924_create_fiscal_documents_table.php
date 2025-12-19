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
        Schema::create('fiscal_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')                        // Cliente que comprou (obrigatório)
                ->constrained('partners');
            $table->foreignId('company_id')                         // Empresa prestadora
                ->constrained('companies')
                ->cascadeOnDelete();
            $table->foreignId('invoice_id')                         // Fatura associada
                ->nullable()
                ->constrained('invoices')
                ->nullOnDelete();
            $table->string('status');
            $table->date('issued_at');
            $table->date('movement_at');
            $table->string('document_type')
                ->nullable();
            $table->string('document_key')
                ->nullable();
            $table->string('document_number')
                ->nullable();
            $table->string('document_series')
                ->nullable();
            $table->tinyInteger('operation_type')
                ->nullable();
            $table->string('operation_nature')
                ->nullable();
            $table->string('issue_purpose')
                ->nullable();
            $table->boolean('is_final_consumer')
                ->default(true);
            $table->boolean('buyer_presence_indicator')
                ->default(true);
            $table->string('tax_observations')
                ->nullable();
            $table->string('additional_tax_information')
                ->nullable();
            $table->string('taxpayer_observations')
                ->nullable();
            $table->string('additional_taxpayer_information')
                ->nullable();
            $table->string('additional_purchase_information')
                ->nullable();
            $table->json('freight_data')
                ->nullable();
            $table->json('payment_data')
                ->nullable();
            $table->json('tax_data')
                ->nullable();
            $table->boolean('pending')
                ->default(true);
            $table->boolean('confirmed')
                ->default(false);
            $table->boolean('canceled')
                ->default(false);
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('confirmed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('canceled_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->datetime('confirmed_at')
                ->nullable();
            $table->datetime('canceled_at')
                ->nullable();
            $table->json('errors_messages')
                ->nullable();
            $table->json('logs')
                ->nullable();
            $table->timestamps();

            // Índices com nomes customizados (evitar limite de 64 caracteres do MySQL)
            $table->unique(['document_number', 'document_series', 'company_id'], 'fd_doc_num_series_company_unique');
            $table->unique(['document_number', 'document_series', 'customer_id'], 'fd_doc_num_series_customer_unique');
            $table->index(['company_id', 'status'], 'fd_company_status_idx');
            $table->index(['company_id', 'customer_id'], 'fd_company_customer_idx');
            $table->index(['company_id', 'document_number'], 'fd_company_doc_num_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_infiscal_documentsvoices');
    }
};
