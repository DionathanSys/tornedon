<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_statement_import_id')
                ->constrained('bank_statement_imports')
                ->cascadeOnDelete();
            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();
            $table->foreignId('financial_account_id')
                ->constrained('financial_accounts')
                ->cascadeOnDelete();
            $table->foreignId('cash_movement_id')
                ->nullable()
                ->constrained('cash_movements')
                ->nullOnDelete();
            $table->date('transaction_date');
            $table->decimal('amount', 15, 4);
            $table->decimal('balance_amount', 15, 4)->nullable();
            $table->string('description');
            $table->string('external_id')->nullable();
            $table->string('document_number')->nullable();
            $table->string('reconciliation_status')->default('pending');
            $table->timestamp('reconciled_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'transaction_date'], 'bsl_company_date_idx');
            $table->index(['financial_account_id', 'reconciliation_status'], 'bsl_account_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_lines');
    }
};
