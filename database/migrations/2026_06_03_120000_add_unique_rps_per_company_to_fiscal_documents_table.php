<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('fiscal_documents', function (Blueprint $table) {
            $table->unique(
                ['company_id', 'document_type', 'rps_series', 'rps_number'],
                'fd_company_doc_type_rps_unique'
            );
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('fiscal_documents', function (Blueprint $table) {
            $table->dropUnique('fd_company_doc_type_rps_unique');
        });
    }
};
