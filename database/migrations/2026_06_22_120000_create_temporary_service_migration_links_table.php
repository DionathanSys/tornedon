<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temporary_service_migration_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->unsignedBigInteger('legacy_id');
            $table->foreignId('service_id')->nullable();
            $table->timestamp('legacy_updated_at')->nullable();
            $table->timestamp('legacy_deleted_at')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('last_imported_at')->nullable();
            $table->timestamps();

            $table->foreign('company_id', 'temp_service_mig_company_fk')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('service_id', 'temp_service_mig_service_fk')->references('id')->on('services')->nullOnDelete();

            $table->unique(['company_id', 'legacy_id'], 'temp_service_migration_company_legacy_unique');
            $table->index('service_id', 'temp_service_mig_service_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temporary_service_migration_links');
    }
};
