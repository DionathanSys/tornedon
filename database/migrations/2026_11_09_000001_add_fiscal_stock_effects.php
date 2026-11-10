<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requisition_items', fn (Blueprint $table) => $table->boolean('stock_control_snapshot')->nullable());
        Schema::table('stock_movements', fn (Blueprint $table) => $table->index(['company_id', 'source_type', 'source_id', 'type'], 'stock_movements_company_source_type_idx'));
        Schema::table('fiscal_document_items', function (Blueprint $table): void {
            $table->foreignId('requisition_item_id')->nullable()->constrained()->restrictOnDelete();
        });
        Schema::create('fiscal_stock_effects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('fiscal_document_id')->constrained()->restrictOnDelete();
            $table->foreignId('fiscal_document_item_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('requisition_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_stock_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('stock_movement_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('stock_control_enabled');
            $table->decimal('base_quantity', 18, 8);
            $table->string('base_unit', 6);
            $table->json('snapshot');
            $table->string('status')->default('prepared');
            $table->text('last_error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_stock_effects');
        Schema::table('stock_movements', fn (Blueprint $table) => $table->dropIndex('stock_movements_company_source_type_idx'));
        Schema::table('fiscal_document_items', fn (Blueprint $table) => $table->dropConstrainedForeignId('requisition_item_id'));
        Schema::table('requisition_items', fn (Blueprint $table) => $table->dropColumn('stock_control_snapshot'));
    }
};
