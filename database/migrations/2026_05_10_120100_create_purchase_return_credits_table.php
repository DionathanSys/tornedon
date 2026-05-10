<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_return_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();
            $table->foreignId('partner_id')
                ->constrained('partners')
                ->cascadeOnDelete();
            $table->foreignId('origin_fiscal_document_id')
                ->constrained('fiscal_documents')
                ->cascadeOnDelete();
            $table->foreignId('return_fiscal_document_id')
                ->constrained('fiscal_documents')
                ->cascadeOnDelete();
            $table->decimal('amount', 15, 4);
            $table->decimal('used_amount', 15, 4)
                ->default(0);
            $table->decimal('net_amount', 15, 4)
                ->virtualAs('amount - used_amount');
            $table->string('status', 40);
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'partner_id', 'status']);
            $table->unique(['return_fiscal_document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_return_credits');
    }
};
