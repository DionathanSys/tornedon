<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table) {
            // Estado do processamento na SEFAZ
            $table->string('nfe_status')
                ->nullable()
                ->after('status');

            // Ambiente: 1 = Produção, 2 = Homologação
            $table->tinyInteger('nfe_ambiente')
                ->nullable()
                ->after('nfe_status');

            // Protocolo de autorização da SEFAZ
            $table->string('nfe_protocolo')
                ->nullable()
                ->after('nfe_ambiente');

            // Payload enviado à API (auditoria)
            $table->json('nfe_payload')
                ->nullable()
                ->after('nfe_protocolo');

            // Vínculo com a sequência de numeração usada
            $table->foreignId('nfe_sequence_id')
                ->nullable()
                ->after('nfe_payload')
                ->constrained('nfe_sequences')
                ->nullOnDelete();

            // Índice para lookup rápido via webhook pela chave de acesso
            $table->index('document_key', 'fd_document_key_idx');
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table) {
            $table->dropIndex('fd_document_key_idx');
            $table->dropConstrainedForeignId('nfe_sequence_id');
            $table->dropColumn(['nfe_status', 'nfe_ambiente', 'nfe_protocolo', 'nfe_payload']);
        });
    }
};
