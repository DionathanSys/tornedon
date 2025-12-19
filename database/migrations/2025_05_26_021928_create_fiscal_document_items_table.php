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
        Schema::create('fiscal_document_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_document_id')
                ->constrained('fiscal_documents')
                ->cascadeOnDelete();
            $table->foreignId('product_id')
                ->constrained('products');
            $table->integer('item_number');
            $table->string('origin_code', 1);
            $table->string('ncm_code', 8);
            $table->string('cfop_code', 4);
            $table->decimal('quantity', 15, 4);
            $table->string('unit_of_measure', 3);
            $table->decimal('unit_price', 15, 4);
            $table->decimal('total_price', 15, 4);
            $table->boolean('included_in_total')
                ->default(true);
            $table->json('tax_data')
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
        Schema::dropIfExists('fiscal_document_items');
    }
};
