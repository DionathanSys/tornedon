<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_receivable_installment_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_receivable_installment_id')
                ->constrained('account_receivable_installments', indexName: 'arip_installment_fk')
                ->cascadeOnDelete();
            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();
            $table->date('payment_date');
            $table->decimal('amount', 15, 4);
            $table->decimal('interest_amount', 15, 4)->default(0);
            $table->decimal('fine_amount', 15, 4)->default(0);
            $table->decimal('discount_amount', 15, 4)->default(0);
            $table->unsignedBigInteger('bank_account_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'payment_date'], 'arip_company_payment_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_receivable_installment_payments');
    }
};
