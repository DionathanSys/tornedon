<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sefaz_distribution_documents', function (Blueprint $table): void {
            $table->foreignId('account_payable_id')
                ->nullable()
                ->after('fiscal_document_id')
                ->constrained('account_payables')
                ->nullOnDelete();

            $table->index(['company_id', 'account_payable_id'], 'sdd_company_account_payable_idx');
        });
    }

    public function down(): void
    {
        Schema::table('sefaz_distribution_documents', function (Blueprint $table): void {
            $table->dropIndex('sdd_company_account_payable_idx');
            $table->dropConstrainedForeignId('account_payable_id');
        });
    }
};
