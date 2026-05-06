<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nfe_invalidation_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('fiscal_document_id')->nullable()->constrained('fiscal_documents')->nullOnDelete();
            $table->string('serie', 3);
            $table->unsignedBigInteger('number_start');
            $table->unsignedBigInteger('number_end');
            $table->text('justification');
            $table->string('status', 20)->default('pending');
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->json('response_payload')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'serie', 'status'], 'nir_company_serie_status_idx');
            $table->unique(['company_id', 'serie', 'number_start', 'number_end'], 'nir_company_serie_number_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfe_invalidation_requests');
    }
};
