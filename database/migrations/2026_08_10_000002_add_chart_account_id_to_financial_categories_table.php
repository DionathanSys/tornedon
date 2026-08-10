<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_categories', function (Blueprint $table) {
            $table->foreignId('chart_account_id')
                ->nullable()
                ->after('company_id')
                ->constrained('chart_accounts')
                ->nullOnDelete();

            $table->index(['company_id', 'chart_account_id']);
        });
    }

    public function down(): void
    {
        Schema::table('financial_categories', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'chart_account_id']);
            $table->dropConstrainedForeignId('chart_account_id');
        });
    }
};
