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
        Schema::create('addresses', function (Blueprint $table) {
            $table->id();                                           // ID único do endereço
            $table->foreignId('partner_id')                         // ID do parceiro (obrigatório)
                ->constrained('partners')
                ->cascadeOnDelete();
            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();
            $table->string('street')
                ->nullable();                                       // Logradouro (rua, avenida, etc.)
            $table->string('number')
                ->nullable();                                       // Número do endereço
            $table->string('complement')
                ->nullable();                                       // Complemento (apto, sala, bloco, etc.)
            $table->string('neighborhood')
                ->nullable();                                       // Bairro
            $table->string('city')
                ->nullable();                                       // Cidade
            $table->string('state')
                ->nullable();                                       // Estado/UF
            $table->string('country')
                ->default('Brasil');                                // País (padrão: Brasil)
            $table->string('postal_code')
                ->nullable();                                       // CEP
            $table->string('city_code')
                ->nullable();                                       // Código IBGE da cidade
            $table->foreignId('created_by')                         // Usuário que criou o registro
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('updated_by')                         // Usuário que atualizou o registro
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();                                   // Data de criação e atualização
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('addresses');
    }
};
