<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temporary_service_order_item_migration_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('legacy_id');
            $table->unsignedBigInteger('legacy_service_order_id');
            $table->unsignedBigInteger('legacy_service_id');
            $table->foreignId('service_order_id')->nullable()->constrained('service_orders')->nullOnDelete();
            $table->foreignId('service_order_item_id')->nullable()->constrained('service_order_items')->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->json('payload')->nullable();
            $table->timestamp('last_imported_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'legacy_id'], 'temp_service_order_item_company_legacy_unique');
            $table->index(['company_id', 'legacy_service_order_id'], 'temp_so_item_company_order_idx');
            $table->index('service_order_item_id', 'temp_so_item_item_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temporary_service_order_item_migration_links');
    }
};
