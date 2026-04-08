<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addFinancialAccountReference('account_payable_installment_payments', 'apip_fin_account_fk');
        $this->addFinancialAccountReference('account_receivable_installment_payments', 'arip_fin_account_fk');
    }

    public function down(): void
    {
        $this->dropFinancialAccountReference('account_receivable_installment_payments', 'arip_fin_account_fk');
        $this->dropFinancialAccountReference('account_payable_installment_payments', 'apip_fin_account_fk');
    }

    private function addFinancialAccountReference(string $tableName, string $foreignKeyName): void
    {
        if (! Schema::hasColumn($tableName, 'financial_account_id')) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->unsignedBigInteger('financial_account_id')
                    ->nullable()
                    ->after('bank_account_id');
            });
        }

        if ($this->foreignKeyExists($tableName, $foreignKeyName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($foreignKeyName) {
            $table->foreign('financial_account_id', $foreignKeyName)
                ->references('id')
                ->on('financial_accounts')
                ->nullOnDelete();
        });
    }

    private function dropFinancialAccountReference(string $tableName, string $foreignKeyName): void
    {
        if ($this->foreignKeyExists($tableName, $foreignKeyName)) {
            Schema::table($tableName, function (Blueprint $table) use ($foreignKeyName) {
                $table->dropForeign($foreignKeyName);
            });
        }

        if (Schema::hasColumn($tableName, 'financial_account_id')) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn('financial_account_id');
            });
        }
    }

    private function foreignKeyExists(string $tableName, string $foreignKeyName): bool
    {
        $databaseName = DB::getDatabaseName();

        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', $databaseName)
            ->where('TABLE_NAME', $tableName)
            ->where('CONSTRAINT_NAME', $foreignKeyName)
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->exists();
    }
};
