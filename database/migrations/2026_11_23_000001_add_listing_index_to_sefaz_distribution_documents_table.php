<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sefaz_distribution_documents', function (Blueprint $table): void {
            $table->index(['company_id', 'last_seen_at', 'id'], 'sdd_company_last_seen_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('sefaz_distribution_documents', function (Blueprint $table): void {
            $table->dropIndex('sdd_company_last_seen_id_idx');
        });
    }
};
