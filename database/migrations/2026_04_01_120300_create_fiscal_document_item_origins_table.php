<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_document_item_origins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('origin_fiscal_document_id')
                ->constrained('fiscal_documents')
                ->cascadeOnDelete();
            $table->foreignId('origin_fiscal_document_item_id')
                ->constrained('fiscal_document_items')
                ->cascadeOnDelete();
            $table->foreignId('return_fiscal_document_id')
                ->constrained('fiscal_documents')
                ->cascadeOnDelete();
            $table->foreignId('return_fiscal_document_item_id')
                ->constrained('fiscal_document_items')
                ->cascadeOnDelete();
            $table->decimal('linked_quantity', 15, 4);
            $table->decimal('linked_value', 15, 2)->default(0);
            $table->string('origin_document_key', 44)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['origin_fiscal_document_id', 'origin_fiscal_document_item_id'], 'fdi_origins_origin_idx');
            $table->index(['return_fiscal_document_id', 'return_fiscal_document_item_id'], 'fdi_origins_return_idx');
            $table->index('origin_document_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_document_item_origins');
    }
};
