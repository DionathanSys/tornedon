<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_receivables', function (Blueprint $table) {
            $table->dropUnique('ar_invoice_fiscal_sequence_unique');
        });

        Schema::table('account_receivables', function (Blueprint $table) {
            $table->dropColumn('sequence_number');
        });

        Schema::table('account_receivables', function (Blueprint $table) {
            $table->unique(
                ['invoice_id', 'fiscal_document_id'],
                'ar_invoice_fiscal_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('account_receivables', function (Blueprint $table) {
            $table->dropUnique('ar_invoice_fiscal_unique');
        });

        Schema::table('account_receivables', function (Blueprint $table) {
            $table->string('sequence_number', 2)
                ->default('01')
                ->after('fiscal_document_id');
        });

        Schema::table('account_receivables', function (Blueprint $table) {
            $table->unique(
                ['invoice_id', 'fiscal_document_id', 'sequence_number'],
                'ar_invoice_fiscal_sequence_unique'
            );
        });
    }
};
