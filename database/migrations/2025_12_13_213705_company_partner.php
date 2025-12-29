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
        Schema::create('company_partner', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();
            $table->foreignId('partner_id')
                ->constrained('partners')
                ->cascadeOnDelete();
            $table->json('type');
            $table->decimal('invoice_threshold')                // Valor mínimo para faturar (por cliente)  !!-> threshold = limite
                ->default(0.00)
                ->nullable();
            $table->boolean('is_active')
                ->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'partner_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_partner');
    }
};
