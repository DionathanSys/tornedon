<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_closing_fiscal_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_closing_id')
                ->constrained('purchase_closings')
                ->cascadeOnDelete();
            $table->foreignId('fiscal_document_id')
                ->constrained('fiscal_documents')
                ->cascadeOnDelete();
            $table->decimal('document_amount', 15, 4);
            $table->decimal('discount_amount', 15, 4)
                ->default(0);
            $table->timestamps();

            $table->unique('fiscal_document_id', 'purchase_closing_fiscal_documents_unique_document');
            $table->unique(['purchase_closing_id', 'fiscal_document_id'], 'purchase_closing_fiscal_documents_unique_closing_document');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_closing_fiscal_documents');
    }
};
