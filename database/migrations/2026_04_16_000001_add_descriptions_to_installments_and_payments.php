<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_payable_installments', function (Blueprint $table) {
            $table->string('description')
                ->nullable()
                ->after('cost_center_id');
        });

        Schema::table('account_receivable_installments', function (Blueprint $table) {
            $table->string('description')
                ->nullable()
                ->after('cost_center_id');
        });

        Schema::table('account_payable_installment_payments', function (Blueprint $table) {
            $table->string('description')
                ->nullable()
                ->after('bank_account_id');
        });

        Schema::table('account_receivable_installment_payments', function (Blueprint $table) {
            $table->string('description')
                ->nullable()
                ->after('bank_account_id');
        });

        DB::table('account_payable_installments')
            ->whereNull('description')
            ->update(['description' => DB::raw('notes')]);

        DB::table('account_receivable_installments')
            ->whereNull('description')
            ->update(['description' => DB::raw('notes')]);
    }

    public function down(): void
    {
        Schema::table('account_receivable_installment_payments', function (Blueprint $table) {
            $table->dropColumn('description');
        });

        Schema::table('account_payable_installment_payments', function (Blueprint $table) {
            $table->dropColumn('description');
        });

        Schema::table('account_receivable_installments', function (Blueprint $table) {
            $table->dropColumn('description');
        });

        Schema::table('account_payable_installments', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
