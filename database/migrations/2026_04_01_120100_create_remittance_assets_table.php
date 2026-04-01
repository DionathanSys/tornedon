<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remittance_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();
            $table->foreignId('fiscal_document_id')
                ->constrained('fiscal_documents')
                ->cascadeOnDelete();
            $table->foreignId('fiscal_document_item_id')
                ->constrained('fiscal_document_items')
                ->cascadeOnDelete();
            $table->foreignId('product_id')
                ->nullable()
                ->constrained('products')
                ->nullOnDelete();
            $table->foreignId('equipment_id')
                ->nullable()
                ->constrained('equipments')
                ->nullOnDelete();
            $table->string('serial_number')->nullable();
            $table->string('lot_number')->nullable();
            $table->decimal('received_quantity', 15, 4)->default(1);
            $table->decimal('returned_quantity', 15, 4)->default(0);
            $table->string('status', 30)->default('received');
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['fiscal_document_id', 'fiscal_document_item_id'], 'remittance_assets_fiscal_idx');
            $table->index(['equipment_id', 'serial_number'], 'remittance_assets_equipment_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remittance_assets');
    }
};
