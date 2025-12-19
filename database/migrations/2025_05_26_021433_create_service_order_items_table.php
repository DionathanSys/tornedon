<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('service_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_order_id')
                ->constrained('service_orders')
                ->cascadeOnDelete();
            $table->foreignId('service_id')
                ->constrained('services');
            $table->string('unit_of_measure');
            $table->decimal('quantity', 15, 3);
            $table->decimal('unit_price', 15, 4);
            $table->decimal('unit_cost', 15, 4)
                ->nullable();
            $table->decimal('discount_percentage', 5, 2)
                ->default(0.00);
            $table->decimal('discount_amount', 15, 2)
                ->default(0.00);
            $table->decimal('subtotal', 15, 2)
                ->virtualAs('quantity * unit_price');
            $table->decimal('total_amount', 15, 2)
                ->virtualAs('subtotal - discount_amount');
            $table->text('observations')
                ->nullable();
            $table->json('additional_info')
                ->nullable();
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_order_items');
    }
};
