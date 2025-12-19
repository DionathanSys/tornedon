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
        Schema::create('account_receivables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')
                ->constrained('partners');
            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();
            $table->foreignId('invoice_id')
                ->constrained('invoices')
                ->cascadeOnDelete();
            $table->string('sequence_number', 2);
            $table->string('status');
            $table->date('due_date');
            $table->date('paid_date');
            $table->decimal('due_amount', 15, 4);
            $table->decimal('paid_amount', 15, 4);
            $table->string('document_number')
                ->nullable();
            $table->string('description')
                ->nullable();
            $table->boolean('paid')
                ->default(false);
            $table->string('type')
                ->nullable();
            $table->string('payment_method')
                ->nullable();
            $table->timestamps();

            $table->index(['company_id', 'customer_id']);
            $table->index(['company_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_receivables');
    }
};
