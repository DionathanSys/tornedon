<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_document_items', function (Blueprint $table) {
            $table->json('fiscal_snapshot')->nullable()->after('tax_data');
            $table->foreignId('fiscal_rule_id')
                ->nullable()
                ->after('fiscal_snapshot')
                ->constrained('fiscal_rules')
                ->nullOnDelete();
            $table->unsignedInteger('fiscal_rule_version')->nullable()->after('fiscal_rule_id');
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_document_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fiscal_rule_id');
            $table->dropColumn(['fiscal_snapshot', 'fiscal_rule_version']);
        });
    }
};
