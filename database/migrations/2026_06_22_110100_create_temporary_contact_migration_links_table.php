<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temporary_contact_migration_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->unsignedBigInteger('legacy_id');
            $table->unsignedBigInteger('legacy_partner_id');
            $table->foreignId('contact_id')->nullable();
            $table->foreignId('company_partner_id')->nullable();
            $table->string('legacy_contact_name')->nullable();
            $table->string('fingerprint', 64)->nullable();
            $table->timestamp('legacy_updated_at')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('last_imported_at')->nullable();
            $table->timestamps();

            $table->foreign('company_id', 'temp_ctt_mig_company_fk')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('contact_id', 'temp_ctt_mig_contact_fk')->references('id')->on('contacts')->nullOnDelete();
            $table->foreign('company_partner_id', 'temp_ctt_mig_company_partner_fk')->references('id')->on('company_partner')->nullOnDelete();

            $table->unique(['company_id', 'legacy_id'], 'temp_contact_migration_company_legacy_unique');
            $table->index(['company_id', 'legacy_partner_id'], 'temp_ctt_mig_company_partner_idx');
            $table->index('contact_id', 'temp_ctt_mig_contact_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temporary_contact_migration_links');
    }
};
