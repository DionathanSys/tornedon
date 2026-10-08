<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('card_payment_profiles', function (Blueprint $table): void {
            $table->boolean('is_default')->default(false);
        });
        Schema::table('account_receivables', function (Blueprint $table): void {
            $table->boolean('auto_register_receipt_on_due_date')->default(false);
            $table->foreignId('auto_receipt_financial_account_id')->nullable()
                ->constrained('financial_accounts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('account_receivables', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('auto_receipt_financial_account_id');
            $table->dropColumn('auto_register_receipt_on_due_date');
        });
        Schema::table('card_payment_profiles', function (Blueprint $table): void {
            $table->dropColumn('is_default');
        });
    }
};
