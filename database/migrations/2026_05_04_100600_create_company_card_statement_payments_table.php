<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_card_statement_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();
            $table->foreignId('company_card_statement_id')
                ->constrained('company_card_statements')
                ->cascadeOnDelete();
            $table->foreignId('account_payable_installment_payment_id')
                ->nullable()
                ->constrained('account_payable_installment_payments')
                ->nullOnDelete();
            $table->date('payment_date');
            $table->decimal('amount', 15, 4);
            $table->foreignId('financial_account_id')
                ->nullable()
                ->constrained('financial_accounts')
                ->nullOnDelete();
            $table->text('notes')
                ->nullable();
            $table->timestamps();

            $table->index(['company_id', 'payment_date'], 'company_card_statement_payments_company_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_card_statement_payments');
    }
};
