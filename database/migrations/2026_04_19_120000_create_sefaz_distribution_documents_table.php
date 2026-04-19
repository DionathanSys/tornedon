<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sefaz_distribution_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();
            $table->foreignId('partner_id')
                ->nullable()
                ->constrained('partners')
                ->nullOnDelete();
            $table->string('document_key', 44);
            $table->string('nsu', 15)->nullable();
            $table->string('schema')->nullable();
            $table->string('document_type')->default('nfe');
            $table->string('issuer_document', 18)->nullable();
            $table->string('issuer_name')->nullable();
            $table->string('document_number')->nullable();
            $table->string('document_series')->nullable();
            $table->dateTime('issued_at')->nullable();
            $table->decimal('total_amount', 15, 2)->nullable();
            $table->string('status');
            $table->string('manifestation_status');
            $table->boolean('full_xml_available')->default(false);
            $table->string('summary_xml_path')->nullable();
            $table->string('full_xml_path')->nullable();
            $table->string('raw_response_path')->nullable();
            $table->json('items_json')->nullable();
            $table->json('distribution_payload')->nullable();
            $table->timestamp('import_ready_at')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'document_key'], 'sdd_company_document_key_unique');
            $table->index(['company_id', 'status'], 'sdd_company_status_idx');
            $table->index(['company_id', 'manifestation_status'], 'sdd_company_manifest_idx');
            $table->index(['company_id', 'nsu'], 'sdd_company_nsu_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sefaz_distribution_documents');
    }
};
