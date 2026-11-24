<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_reconciliation_groups', function (Blueprint $table): void {
            $table->string('operation_type')->default('standard');
            $table->string('status')->nullable()->index();
            $table->uuid('idempotency_key')->nullable();
            $table->string('request_hash', 64)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->unique(['company_id', 'idempotency_key'], 'brg_company_idempotency_unique');
        });
        Schema::create('bank_reconciliation_group_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('bank_reconciliation_group_id')->constrained(indexName: 'brgi_group_fk')->restrictOnDelete();
            $table->foreignId('bank_statement_line_id')->constrained()->restrictOnDelete();
            $table->string('role');
            $table->foreignId('receipt_item_id')->nullable()->constrained('bank_reconciliation_group_items', indexName: 'brgi_receipt_fk')->restrictOnDelete();
            $table->foreignId('receivable_payment_id')->nullable()->unique()->constrained('account_receivable_installment_payments', indexName: 'brgi_receivable_fk')->restrictOnDelete();
            $table->foreignId('payable_payment_id')->nullable()->unique()->constrained('account_payable_installment_payments', indexName: 'brgi_payable_fk')->restrictOnDelete();
            $table->foreignId('cash_movement_id')->index()->constrained()->restrictOnDelete();
            $table->unique(['bank_reconciliation_group_id', 'cash_movement_id'], 'brgi_group_movement_unique');
            $table->boolean('created_payment')->default(true);
            $table->boolean('created_document')->default(false);
            $table->timestamp('returned_at')->nullable();
            $table->timestamps();
        });
        Schema::table('account_payable_installment_payments', function (Blueprint $table): void {
            // Same scale as the existing payable payment amount (decimal reais).
            $table->decimal('returned_amount', 15, 4)->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_reconciliation_group_items');
        Schema::table('account_payable_installment_payments', fn (Blueprint $table) => $table->dropColumn('returned_amount'));
        Schema::table('bank_reconciliation_groups', function (Blueprint $table): void {
            $table->dropUnique('brg_company_idempotency_unique');
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['operation_type', 'status', 'idempotency_key', 'request_hash', 'confirmed_at', 'cancelled_at', 'cancellation_reason']);
        });
    }
};
