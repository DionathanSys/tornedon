<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_payable_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_payable_id')->constrained('account_payables')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('sequence_number', 3);
            $table->string('status')->index();
            $table->date('due_date');
            $table->date('paid_date')->nullable();
            $table->decimal('original_amount', 15, 4);
            $table->decimal('interest_amount', 15, 4)->default(0);
            $table->decimal('fine_amount', 15, 4)->default(0);
            $table->decimal('discount_amount', 15, 4)->default(0);
            $table->decimal('due_amount', 15, 4);
            $table->decimal('paid_amount', 15, 4)->default(0);
            $table->decimal('balance_amount', 15, 4);
            $table->unsignedBigInteger('bank_account_id')->nullable();
            $table->unsignedBigInteger('financial_category_id')->nullable();
            $table->unsignedBigInteger('cost_center_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['account_payable_id', 'sequence_number']);
            $table->index(['company_id', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_payable_installments');
    }
};
