<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_reconciliation_evaluations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('token')->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('import_id')->nullable()->constrained('bank_statement_imports')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('context_hash', 64);
            $table->unsignedInteger('engine_version');
            $table->json('line_ids');
            $table->json('bank_snapshot');
            $table->json('recommendations');
            $table->boolean('complete');
            $table->boolean('has_suggestions');
            $table->unsignedInteger('duration_ms');
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'created_at'], 'reconciliation_evaluation_period');
        });

        Schema::create('bank_reconciliation_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('financial_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('import_id')->nullable()->constrained('bank_statement_imports')->nullOnDelete();
            $table->foreignId('line_id')->nullable()->constrained('bank_statement_lines')->nullOnDelete();
            $table->foreignId('evaluation_id')->nullable()->constrained('bank_reconciliation_evaluations')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('group_id')->nullable();
            $table->string('direction', 10);
            $table->string('bank_fingerprint', 64)->nullable();
            $table->string('party_key', 80)->nullable();
            $table->json('line_ids');
            $table->json('bank_snapshot');
            $table->json('selected_targets');
            $table->boolean('single_target');
            $table->unsignedTinyInteger('individual_rank')->nullable();
            $table->unsignedTinyInteger('bundle_rank')->nullable();
            $table->timestamp('confirmed_at');
            $table->timestamp('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'confirmed_at'], 'reconciliation_decision_period');
            $table->index(['company_id', 'financial_account_id', 'bank_fingerprint'], 'reconciliation_history_lookup');
            $table->index(['company_id', 'group_id', 'line_id'], 'reconciliation_decision_source');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_reconciliation_decisions');
        Schema::dropIfExists('bank_reconciliation_evaluations');
    }
};
