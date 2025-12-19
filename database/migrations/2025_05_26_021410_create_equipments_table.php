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
        Schema::create('equipments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('owner_id')
                ->nullable()
                ->constrained('partners')
                ->nullOnDelete();
            $table->foreignId('company_id')                         // Empresa vendedora
                ->constrained('companies')
                ->cascadeOnDelete();
            $table->string('type')
                ->nullable();
            $table->string('placa', 7)
                ->nullable();
            $table->string('model')
                ->nullable();
            $table->string('serial_number')
                ->nullable();
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['placa', 'company_id']);
            $table->unique(['serial_number', 'company_id', 'name', 'owner_id']);
            $table->index(['company_id', 'owner_id']);
            $table->index(['company_id', 'serial_number']);
            $table->index(['company_id', 'placa']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('equipaments');
    }
};
