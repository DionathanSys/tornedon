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
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('legacy_id');
            $table->unsignedBigInteger('legacy_partner_id');
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('company_partner_id')->nullable()->constrained('company_partner')->nullOnDelete();
            $table->string('legacy_contact_name')->nullable();
            $table->string('fingerprint', 64)->nullable();
            $table->timestamp('legacy_updated_at')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('last_imported_at')->nullable();
            $table->timestamps();

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
