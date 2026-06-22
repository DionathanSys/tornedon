<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temporary_partner_migration_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->unsignedBigInteger('legacy_id');
            $table->foreignId('partner_id')->nullable();
            $table->foreignId('company_partner_id')->nullable();
            $table->string('legacy_document_number')->nullable();
            $table->timestamp('legacy_updated_at')->nullable();
            $table->timestamp('legacy_deleted_at')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('last_imported_at')->nullable();
            $table->timestamps();

            $table->foreign('company_id', 'temp_partner_mig_company_fk')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('partner_id', 'temp_partner_mig_partner_fk')->references('id')->on('partners')->nullOnDelete();
            $table->foreign('company_partner_id', 'temp_partner_mig_company_partner_fk')->references('id')->on('company_partner')->nullOnDelete();

            $table->unique(['company_id', 'legacy_id'], 'temp_partner_migration_company_legacy_unique');
            $table->index('partner_id', 'temp_partner_mig_partner_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temporary_partner_migration_links');
    }
};
