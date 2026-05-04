<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_card_statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();
            $table->foreignId('company_credit_card_id')
                ->constrained('company_credit_cards')
                ->cascadeOnDelete();
            $table->date('reference_month');
            $table->date('period_start');
            $table->date('period_end');
            $table->date('cutoff_date');
            $table->date('closing_date');
            $table->date('due_date');
            $table->decimal('gross_total', 15, 4)
                ->default(0);
            $table->decimal('fees_total', 15, 4)
                ->default(0);
            $table->decimal('net_total', 15, 4)
                ->default(0);
            $table->decimal('paid_total', 15, 4)
                ->default(0);
            $table->decimal('balance_total', 15, 4)
                ->default(0);
            $table->string('status', 30)
                ->default('open');
            $table->foreignId('account_payable_id')
                ->nullable()
                ->constrained('account_payables')
                ->nullOnDelete();
            $table->timestamp('closed_at')
                ->nullable();
            $table->timestamp('paid_at')
                ->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'company_credit_card_id', 'reference_month'], 'company_card_statements_company_card_refmonth_unique');
            $table->index(['company_id', 'company_credit_card_id', 'cutoff_date'], 'company_card_statements_company_card_cutoff_idx');
            $table->index(['company_id', 'status', 'due_date'], 'company_card_statements_company_status_due_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_card_statements');
    }
};
