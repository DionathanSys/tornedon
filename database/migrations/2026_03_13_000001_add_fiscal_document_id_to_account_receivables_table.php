<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_receivables', function (Blueprint $table) {
            $table->foreignId('fiscal_document_id')
                ->nullable()
                ->after('invoice_id')
                ->constrained('fiscal_documents')
                ->nullOnDelete();

            $table->unique(
                ['invoice_id', 'fiscal_document_id', 'sequence_number'],
                'ar_invoice_fiscal_sequence_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('account_receivables', function (Blueprint $table) {
            $table->dropUnique('ar_invoice_fiscal_sequence_unique');
            $table->dropConstrainedForeignId('fiscal_document_id');
        });
    }
};
