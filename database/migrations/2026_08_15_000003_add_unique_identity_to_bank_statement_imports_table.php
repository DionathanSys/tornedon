<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_statement_imports', function (Blueprint $table) {
            $table->unique([
                'company_id',
                'financial_account_id',
                'source',
                'reference',
            ], 'bsi_company_account_source_reference_unique');
        });
    }

    public function down(): void
    {
        Schema::table('bank_statement_imports', function (Blueprint $table) {
            $table->dropUnique('bsi_company_account_source_reference_unique');
        });
    }
};
