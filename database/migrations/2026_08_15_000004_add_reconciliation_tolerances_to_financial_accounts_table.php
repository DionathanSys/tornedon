<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_accounts', function (Blueprint $table) {
            $table->decimal('reconciliation_amount_tolerance', 15, 4)->default(5)->after('opening_balance');
            $table->unsignedTinyInteger('reconciliation_date_tolerance_days')->default(3)->after('reconciliation_amount_tolerance');
        });
    }

    public function down(): void
    {
        Schema::table('financial_accounts', function (Blueprint $table) {
            $table->dropColumn([
                'reconciliation_amount_tolerance',
                'reconciliation_date_tolerance_days',
            ]);
        });
    }
};
