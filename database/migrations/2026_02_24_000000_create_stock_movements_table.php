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
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();

            // Relations
            $table->foreignId('product_stock_id')
                ->constrained('product_stocks')
                ->cascadeOnDelete();
            $table->foreignId('product_id')
                ->constrained('products')
                ->cascadeOnDelete();
            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();

            // Movement Data
            $table->string('type');
            $table->decimal('quantity', 12, 3);
            $table->decimal('unit_price', 12, 4)
                ->nullable();
            $table->decimal('total_amount', 15, 2)
                ->virtualAs('quantity * unit_price');

            // Additional info
            $table->text('reason')
                ->nullable();
            $table->text('observations')
                ->nullable();
            $table->morphs('source'); // source_type, source_id
            $table->json('additional_info')
                ->nullable();

            // Audit
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            // Indexes
            $table->index(['product_stock_id', 'created_at']);
            $table->index(['company_id', 'created_at']);
            $table->index(['type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
