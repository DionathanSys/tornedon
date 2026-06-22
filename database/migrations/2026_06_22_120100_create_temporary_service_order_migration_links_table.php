<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temporary_service_order_migration_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('legacy_id');
            $table->unsignedBigInteger('legacy_partner_id');
            $table->unsignedBigInteger('legacy_equipment_id');
            $table->unsignedBigInteger('legacy_invoice_id')->nullable();
            $table->foreignId('service_order_id')->nullable()->constrained('service_orders')->nullOnDelete();
            $table->foreignId('customer_partner_id')->nullable()->constrained('partners')->nullOnDelete();
            $table->foreignId('equipment_id')->nullable()->constrained('equipments')->nullOnDelete();
            $table->timestamp('legacy_updated_at')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('last_imported_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'legacy_id'], 'temp_service_order_company_legacy_unique');
            $table->index(['company_id', 'legacy_partner_id'], 'temp_so_mig_company_partner_idx');
            $table->index(['company_id', 'legacy_equipment_id'], 'temp_so_mig_company_equipment_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temporary_service_order_migration_links');
    }
};
