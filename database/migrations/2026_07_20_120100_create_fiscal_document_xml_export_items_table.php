<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_document_xml_export_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fiscal_document_xml_export_id');
            $table->foreignId('fiscal_document_id');
            $table->string('document_key', 60);
            $table->string('document_number')->nullable();
            $table->string('status', 32)->index();
            $table->string('xml_disk')->nullable();
            $table->string('xml_path')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['fiscal_document_xml_export_id', 'fiscal_document_id'], 'fd_xml_export_item_unique');
            $table->index(['fiscal_document_xml_export_id', 'status'], 'fd_xml_export_item_status_index');
            $table->foreign('fiscal_document_xml_export_id', 'fd_xml_export_item_export_fk')
                ->references('id')
                ->on('fiscal_document_xml_exports')
                ->cascadeOnDelete();
            $table->foreign('fiscal_document_id', 'fd_xml_export_item_document_fk')
                ->references('id')
                ->on('fiscal_documents')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_document_xml_export_items');
    }
};
