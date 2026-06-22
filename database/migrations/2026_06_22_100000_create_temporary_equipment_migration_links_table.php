<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temporary_equipment_migration_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('legacy_id');
            $table->unsignedBigInteger('legacy_partner_id');
            $table->foreignId('equipment_id')->nullable()->constrained('equipments')->nullOnDelete();
            $table->foreignId('owner_partner_id')->nullable()->constrained('partners')->nullOnDelete();
            $table->string('fingerprint', 64)->nullable();
            $table->timestamp('legacy_updated_at')->nullable();
            $table->timestamp('legacy_deleted_at')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('last_imported_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'legacy_id'], 'temp_equipment_migration_company_legacy_unique');
            $table->index(['company_id', 'legacy_partner_id'], 'temp_eq_mig_company_partner_idx');
            $table->index('equipment_id', 'temp_eq_mig_equipment_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temporary_equipment_migration_links');
    }
};
