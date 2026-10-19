<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commitment_settings', function (Blueprint $table) {
            $table->id();
            $this->context($table);
            $table->boolean('is_enabled')->default(false);
            $table->string('parser')->default('chapeco');
            $table->foreignId('nfse_service_id')->nullable()->constrained('services')->restrictOnDelete();
            $table->string('payment_method')->nullable();
            $table->string('payment_condition')->nullable();
            $table->foreignId('financial_category_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('financial_account_id')->nullable()->constrained()->restrictOnDelete();
            $table->text('nfse_footer_message')->nullable();
            $this->actor($table);
            $table->timestamps();
            $table->unique(['company_id', 'customer_id'], 'commitment_settings_context_unique');
        });

        Schema::create('commitment_imports', function (Blueprint $table) {
            $table->id();
            $this->context($table);
            $table->string('type');
            $table->string('status')->default('queued');
            $this->actor($table);
            $table->timestamps();
        });

        Schema::create('commitment_import_files', function (Blueprint $table) {
            $table->id();
            $this->context($table);
            $table->foreignId('commitment_import_id')->constrained()->restrictOnDelete();
            $table->string('type');
            $table->char('sha256', 64);
            $table->string('original_name');
            $table->string('status')->default('queued');
            $table->string('parser_version')->nullable();
            $table->longText('extracted_text')->nullable();
            $table->json('extracted_data')->nullable();
            $table->unsignedInteger('page_count')->nullable();
            $table->unsignedInteger('line_count')->default(0);
            $table->bigInteger('declared_total')->nullable();
            $table->bigInteger('extracted_total')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'customer_id', 'type', 'sha256'], 'commitment_file_identity_unique');
        });

        Schema::create('customer_commitments', function (Blueprint $table) {
            $table->id();
            $this->context($table);
            $table->foreignId('commitment_import_file_id')->constrained()->restrictOnDelete();
            $table->string('number', 40);
            $table->unsignedSmallInteger('fiscal_year');
            $table->date('issued_at');
            $table->string('patrimony', 60)->nullable();
            $table->text('object')->nullable();
            $table->bigInteger('amount');
            $table->bigInteger('retained_amount')->default(0);
            $table->bigInteger('net_amount');
            $table->unsignedInteger('page')->default(1);
            $table->json('original_data');
            $table->json('corrected_data')->nullable();
            $table->text('review_reason')->nullable();
            $table->string('status')->default('unmatched');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'customer_id', 'number', 'fiscal_year'], 'customer_commitment_identity_unique');
            $table->index(['company_id', 'customer_id', 'patrimony'], 'customer_commitment_patrimony_index');
        });

        Schema::create('commitment_billings', function (Blueprint $table) {
            $table->id();
            $this->context($table);
            $table->foreignId('customer_commitment_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('service_order_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('status')->default('pending');
            $table->json('approval_snapshot');
            $table->json('settings_snapshot')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at');
            $table->timestamps();
        });

        Schema::create('commitment_receipts', function (Blueprint $table) {
            $table->id();
            $this->context($table);
            $table->foreignId('customer_commitment_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('financial_account_id')->constrained()->restrictOnDelete();
            $table->char('idempotency_key', 64);
            $table->date('payment_date');
            $table->bigInteger('amount');
            $table->string('status')->default('pending');
            $this->actor($table);
            $table->timestamps();
            $table->unique(['company_id', 'customer_id', 'idempotency_key'], 'commitment_receipt_identity_unique');
        });

        Schema::create('commitment_payment_lines', function (Blueprint $table) {
            $table->id();
            $this->context($table);
            $table->foreignId('commitment_import_file_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('position');
            $table->unsignedInteger('page_start');
            $table->unsignedInteger('page_end');
            $table->string('commitment_number', 40);
            $table->unsignedSmallInteger('fiscal_year');
            $table->string('patrimony', 60)->nullable();
            $table->string('fiscal_document_number')->nullable();
            $table->date('fiscal_document_date')->nullable();
            $table->date('payment_date');
            $table->bigInteger('amount');
            $table->text('object')->nullable();
            $table->json('original_data');
            $table->string('status')->default('unmatched');
            $table->text('review_reason')->nullable();
            $table->foreignId('customer_commitment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('commitment_receipt_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['commitment_import_file_id', 'position'], 'commitment_payment_line_position_unique');
        });

        Schema::create('commitment_receipt_payments', function (Blueprint $table) {
            $table->id();
            $this->context($table);
            $table->foreignId('commitment_receipt_id')->constrained()->restrictOnDelete();
            // Preserve the link when an existing financial payment is removed/reversed.
            $table->foreignId('payment_id')->nullable()->unique()->constrained('account_receivable_installment_payments')->nullOnDelete();
            $table->json('payment_snapshot');
            $table->timestamps();
        });
    }

    private function context(Blueprint $table): void
    {
        $table->foreignId('company_id')->constrained()->restrictOnDelete();
        $table->foreignId('customer_id')->constrained('partners')->restrictOnDelete();
        $table->index(['company_id', 'customer_id']);
    }

    private function actor(Blueprint $table): void
    {
        $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
    }

    public function down(): void
    {
        foreach (['commitment_receipt_payments', 'commitment_payment_lines', 'commitment_receipts', 'commitment_billings', 'customer_commitments', 'commitment_import_files', 'commitment_imports', 'commitment_settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
