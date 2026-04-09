<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_movements', function (Blueprint $table) {
            $table->foreignId('counterparty_partner_id')
                ->nullable()
                ->after('origin_id')
                ->constrained('partners')
                ->nullOnDelete();
            $table->foreignId('counterparty_financial_account_id')
                ->nullable()
                ->after('counterparty_partner_id')
                ->constrained('financial_accounts')
                ->nullOnDelete();
            $table->json('participants_snapshot')
                ->nullable()
                ->after('notes');

            $table->index('counterparty_partner_id');
            $table->index('counterparty_financial_account_id', 'cash_movements_counterparty_fin_acc_idx');
        });
    }

    public function down(): void
    {
        Schema::table('cash_movements', function (Blueprint $table) {
            $table->dropIndex(['counterparty_partner_id']);
            $table->dropIndex('cash_movements_counterparty_fin_acc_idx');
            $table->dropConstrainedForeignId('counterparty_financial_account_id');
            $table->dropConstrainedForeignId('counterparty_partner_id');
            $table->dropColumn('participants_snapshot');
        });
    }
};
