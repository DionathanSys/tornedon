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
        //Parceiros (Clientes e Fornecedores)

        Schema::create('partners', function (Blueprint $table) {
            $table->id();
            $table->string('name')
                ->unique();
            $table->json('type');
            $table->string('document_type');
            $table->string('document_number')
                ->unique();
            $table->boolean('is_active')
                ->default(true);
            $table->string('state_registration')                    // Inscrição Estadual
                ->nullable();
            $table->string('state_registration_indicator')          // Indicador da IE
                ->nullable();
            $table->string('municipal_registration')->nullable();   // Inscrição Municipal
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('partners');
    }
};
