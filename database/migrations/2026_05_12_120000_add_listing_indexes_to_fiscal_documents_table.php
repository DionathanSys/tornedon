<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table) {
            $table->index(
                ['company_id', 'operation_type', 'nfe_status', 'created_at', 'id'],
                'fd_company_operation_nfe_created_idx'
            );

            $table->index(
                ['company_id', 'operation_type', 'nfse_status', 'created_at', 'id'],
                'fd_company_operation_nfse_created_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table) {
            $table->dropIndex('fd_company_operation_nfe_created_idx');
            $table->dropIndex('fd_company_operation_nfse_created_idx');
        });
    }
};
