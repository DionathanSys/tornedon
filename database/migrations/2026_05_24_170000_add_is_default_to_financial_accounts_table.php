<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_accounts', function (Blueprint $table) {
            $table->boolean('is_default')
                ->default(false)
                ->after('is_active');

            $table->index(['company_id', 'is_default'], 'financial_accounts_company_default_idx');
        });

        $companyIds = DB::table('financial_accounts')
            ->distinct()
            ->pluck('company_id');

        foreach ($companyIds as $companyId) {
            $defaultAccountId = DB::table('financial_accounts')
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->orderByDesc('is_active')
                ->orderBy('id')
                ->value('id');

            if ($defaultAccountId === null) {
                continue;
            }

            DB::table('financial_accounts')
                ->where('id', $defaultAccountId)
                ->update(['is_default' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('financial_accounts', function (Blueprint $table) {
            $table->dropIndex('financial_accounts_company_default_idx');
            $table->dropColumn('is_default');
        });
    }
};
