<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_card_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();
            $table->foreignId('company_credit_card_id')
                ->constrained('company_credit_cards')
                ->cascadeOnDelete();
            $table->date('transaction_date');
            $table->date('posting_date')
                ->nullable();
            $table->string('description', 255);
            $table->foreignId('vendor_id')
                ->nullable()
                ->constrained('partners')
                ->nullOnDelete();
            $table->decimal('amount', 15, 4);
            $table->unsignedInteger('installments')
                ->default(1);
            $table->unsignedInteger('current_installment')
                ->default(1);
            $table->uuid('installment_group_uuid')
                ->nullable();
            $table->foreignId('parent_transaction_id')
                ->nullable()
                ->constrained('company_card_transactions')
                ->nullOnDelete();
            $table->foreignId('category_id')
                ->nullable()
                ->constrained('financial_categories')
                ->nullOnDelete();
            $table->unsignedBigInteger('cost_center_id')
                ->nullable();
            $table->string('source_type', 80)
                ->nullable();
            $table->unsignedBigInteger('source_id')
                ->nullable();
            $table->string('source_description', 255)
                ->nullable();
            $table->date('statement_reference_month')
                ->nullable();
            $table->string('status', 30)
                ->default('pending');
            $table->json('meta')
                ->nullable();
            $table->timestamps();

            $table->index(['company_id', 'company_credit_card_id', 'transaction_date'], 'company_card_transactions_company_card_date_idx');
            $table->index(['company_id', 'status'], 'company_card_transactions_company_status_idx');
            $table->index(['company_id', 'statement_reference_month'], 'company_card_transactions_company_refmonth_idx');
            $table->unique(['company_id', 'source_type', 'source_id', 'current_installment'], 'company_card_tx_source_installment_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_card_transactions');
    }
};
