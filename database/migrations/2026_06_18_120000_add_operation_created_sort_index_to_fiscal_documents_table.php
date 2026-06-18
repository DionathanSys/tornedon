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
                ['company_id', 'operation_type', 'created_at', 'id'],
                'fd_company_operation_created_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table) {
            $table->dropIndex('fd_company_operation_created_idx');
        });
    }
};
