<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_payable_installments', function (Blueprint $table): void {
            $table->string('bank_slip_code', 48)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('account_payable_installments', function (Blueprint $table): void {
            $table->dropColumn('bank_slip_code');
        });
    }
};
