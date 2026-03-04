<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nfe_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();
            $table->string('serie', 3)
                ->default('1');
            $table->string('operation_nature', 100);   // ex: "VENDA DENTRO DO ESTADO"
            $table->unsignedBigInteger('last_number')
                ->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'serie', 'operation_nature'], 'nfe_seq_company_serie_nature_unique');
            $table->index('company_id', 'nfe_seq_company_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfe_sequences');
    }
};
