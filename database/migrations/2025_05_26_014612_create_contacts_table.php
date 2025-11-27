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
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();                                           // ID único do contato
            $table->foreignId('partner_id')                         // ID do parceiro (obrigatório)
                ->constrained('partners');
            $table->string('email')                                 // E-mail do contato (único)
                ->unique();
            $table->string('phone')                                 // Telefone fixo
                ->nullable();
            $table->string('mobile')                                // Telefone celular
                ->nullable();
            $table->boolean('notify')                               // Recebe notificações? (padrão: não)
                ->default(false);
            $table->boolean('is_active')                            // Contato ativo? (padrão: sim)
                ->default(true);
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
        Schema::dropIfExists('contacts');
    }
};
