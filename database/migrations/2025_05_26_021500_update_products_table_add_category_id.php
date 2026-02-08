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
        Schema::table('products', function (Blueprint $table) {
            // Remove a coluna antiga de categoria (string)
            $table->dropColumn('category');
            
            // Adiciona a foreign key para categories
            $table->foreignId('category_id')
                ->nullable()
                ->after('description')
                ->constrained('categories')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
            $table->dropColumn('category_id');
            
            // Restaura a coluna antiga
            $table->string('category')
                ->nullable()
                ->after('description');
        });
    }
};
