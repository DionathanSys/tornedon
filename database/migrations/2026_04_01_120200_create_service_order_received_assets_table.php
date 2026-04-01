<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_order_received_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_order_id')
                ->constrained('service_orders')
                ->cascadeOnDelete();
            $table->foreignId('remittance_asset_id')
                ->constrained('remittance_assets')
                ->cascadeOnDelete();
            $table->decimal('quantity_allocated', 15, 4)->default(1);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['service_order_id', 'remittance_asset_id'], 'so_received_assets_unique');
            $table->index('remittance_asset_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_order_received_assets');
    }
};
