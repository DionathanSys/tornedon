<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['account_payable_installment_payments', 'account_receivable_installment_payments'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                // Existing rows remain unidentified rather than inferring their origin.
                $table->string('origin')->nullable();
                $table->timestamp('bank_confirmed_at')->nullable();
            });
        }
        foreach (['bank_slips', 'pix_charges'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->foreignId('financial_account_id')->nullable()->constrained('financial_accounts')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['bank_slips', 'pix_charges'] as $table) {
            Schema::table($table, fn (Blueprint $table) => $table->dropConstrainedForeignId('financial_account_id'));
        }
        foreach (['account_payable_installment_payments', 'account_receivable_installment_payments'] as $table) {
            Schema::table($table, fn (Blueprint $table) => $table->dropColumn(['origin', 'bank_confirmed_at']));
        }
    }
};
