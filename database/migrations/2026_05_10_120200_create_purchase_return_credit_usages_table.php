<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_return_credit_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_return_credit_id')
                ->constrained('purchase_return_credits')
                ->cascadeOnDelete();
            $table->foreignId('partner_id')
                ->constrained('partners')
                ->cascadeOnDelete();
            $table->foreignId('fiscal_document_id')
                ->nullable()
                ->constrained('fiscal_documents')
                ->nullOnDelete();
            $table->foreignId('account_payable_id')
                ->nullable()
                ->constrained('account_payables')
                ->nullOnDelete();
            $table->decimal('amount_used', 15, 4);
            $table->timestamp('used_at');
            $table->text('notes')
                ->nullable();
            $table->json('metadata')
                ->nullable();
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();

            $table->index('purchase_return_credit_id', 'prcu_credit_idx');
            $table->index('used_at', 'prcu_used_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_return_credit_usages');
    }
};
