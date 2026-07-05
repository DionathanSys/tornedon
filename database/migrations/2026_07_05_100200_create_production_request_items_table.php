<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_request_id')
                ->constrained('production_requests')
                ->cascadeOnDelete();
            $table->foreignId('product_id')
                ->constrained('products')
                ->cascadeOnDelete();
            $table->text('description')->nullable();
            $table->string('unit_of_measure');
            $table->decimal('quantity', 15, 3);
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('discount_percentage', 5, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2)
                ->virtualAs('ROUND((quantity * unit_price) - discount_amount, 2)');
            $table->unsignedInteger('sequence')->default(1);
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();

            $table->index(['production_request_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_request_items');
    }
};
