<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_payables', function (Blueprint $table) {
            $table->boolean('auto_register_payment_on_due_date')
                ->default(false)
                ->after('is_effective');
            $table->unsignedBigInteger('auto_payment_financial_account_id')
                ->nullable()
                ->after('auto_register_payment_on_due_date');
        });
    }

    public function down(): void
    {
        Schema::table('account_payables', function (Blueprint $table) {
            $table->dropColumn([
                'auto_register_payment_on_due_date',
                'auto_payment_financial_account_id',
            ]);
        });
    }
};
