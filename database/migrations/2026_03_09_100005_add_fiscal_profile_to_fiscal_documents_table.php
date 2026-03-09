<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table) {
            $table->foreignId('fiscal_profile_version_id')
                ->nullable()
                ->after('nfe_sequence_id')
                ->constrained('fiscal_profile_versions')
                ->nullOnDelete();
            $table->string('tax_regime_used', 30)
                ->nullable()
                ->after('fiscal_profile_version_id');
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fiscal_profile_version_id');
            $table->dropColumn('tax_regime_used');
        });
    }
};
