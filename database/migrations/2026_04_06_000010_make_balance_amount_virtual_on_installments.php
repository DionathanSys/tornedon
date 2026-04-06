<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $this->recreateVirtualBalanceColumn(
            table: 'account_payable_installments',
            sourceColumn: 'paid_amount',
            afterColumn: 'paid_amount',
        );

        $this->recreateVirtualBalanceColumn(
            table: 'account_receivable_installments',
            sourceColumn: 'received_amount',
            afterColumn: 'received_amount',
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE account_payable_installments DROP COLUMN balance_amount');
        DB::statement('ALTER TABLE account_payable_installments ADD balance_amount DECIMAL(15,4) NOT NULL DEFAULT 0 AFTER paid_amount');
        DB::statement('UPDATE account_payable_installments SET balance_amount = GREATEST(due_amount - paid_amount, 0)');

        DB::statement('ALTER TABLE account_receivable_installments DROP COLUMN balance_amount');
        DB::statement('ALTER TABLE account_receivable_installments ADD balance_amount DECIMAL(15,4) NOT NULL DEFAULT 0 AFTER received_amount');
        DB::statement('UPDATE account_receivable_installments SET balance_amount = GREATEST(due_amount - received_amount, 0)');
    }

    private function recreateVirtualBalanceColumn(string $table, string $sourceColumn, string $afterColumn): void
    {
        DB::statement("ALTER TABLE {$table} DROP COLUMN balance_amount");
        DB::statement(
            "ALTER TABLE {$table}
            ADD COLUMN balance_amount DECIMAL(15,4)
            GENERATED ALWAYS AS (GREATEST(due_amount - {$sourceColumn}, 0)) VIRTUAL
            AFTER {$afterColumn}"
        );
    }
};
