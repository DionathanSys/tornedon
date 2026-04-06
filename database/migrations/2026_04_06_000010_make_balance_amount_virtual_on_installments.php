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

        DB::statement(
            'ALTER TABLE account_payable_installments
            MODIFY balance_amount DECIMAL(15,4)
            GENERATED ALWAYS AS (GREATEST(due_amount - paid_amount, 0)) VIRTUAL'
        );

        DB::statement(
            'ALTER TABLE account_receivable_installments
            MODIFY balance_amount DECIMAL(15,4)
            GENERATED ALWAYS AS (GREATEST(due_amount - received_amount, 0)) VIRTUAL'
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
};
