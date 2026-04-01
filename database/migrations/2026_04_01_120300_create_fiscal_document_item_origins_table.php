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
            $table->unsignedBigInteger('origin_fiscal_document_id');
            $table->unsignedBigInteger('origin_fiscal_document_item_id');
            $table->unsignedBigInteger('return_fiscal_document_id');
            $table->unsignedBigInteger('return_fiscal_document_item_id');
            $table->decimal('linked_quantity', 15, 4);
            $table->decimal('linked_value', 15, 2)->default(0);
            $table->string('origin_document_key', 44)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('origin_fiscal_document_id', 'fdi_origins_origin_doc_fk')
                ->references('id')
                ->on('fiscal_documents')
                ->cascadeOnDelete();
            $table->foreign('origin_fiscal_document_item_id', 'fdi_origins_origin_item_fk')
                ->references('id')
                ->on('fiscal_document_items')
                ->cascadeOnDelete();
            $table->foreign('return_fiscal_document_id', 'fdi_origins_return_doc_fk')
                ->references('id')
                ->on('fiscal_documents')
                ->cascadeOnDelete();
            $table->foreign('return_fiscal_document_item_id', 'fdi_origins_return_item_fk')
                ->references('id')
                ->on('fiscal_document_items')
                ->cascadeOnDelete();

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
