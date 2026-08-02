<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->ensureIndex('account_receivables', ['invoice_id'], 'ar_invoice_fk_idx');
        $this->ensureIndex('account_receivables', ['fiscal_document_id'], 'ar_fiscal_document_fk_idx');

        Schema::table('account_receivables', function (Blueprint $table) {
            if ($this->indexExists('account_receivables', 'ar_invoice_fiscal_sequence_unique')) {
                $table->dropUnique('ar_invoice_fiscal_sequence_unique');
            }
        });

        if (Schema::hasColumn('account_receivables', 'sequence_number')) {
            Schema::table('account_receivables', function (Blueprint $table) {
                $table->dropColumn('sequence_number');
            });
        }

        Schema::table('account_receivables', function (Blueprint $table) {
            if (! $this->indexExists('account_receivables', 'ar_invoice_fiscal_unique')) {
                $table->unique(
                    ['invoice_id', 'fiscal_document_id'],
                    'ar_invoice_fiscal_unique'
                );
            }
        });
    }

    public function down(): void
    {
        Schema::table('account_receivables', function (Blueprint $table) {
            if ($this->indexExists('account_receivables', 'ar_invoice_fiscal_unique')) {
                $table->dropUnique('ar_invoice_fiscal_unique');
            }
        });

        if (! Schema::hasColumn('account_receivables', 'sequence_number')) {
            Schema::table('account_receivables', function (Blueprint $table) {
                $table->string('sequence_number', 2)
                    ->default('01')
                    ->after('fiscal_document_id');
            });
        }

        Schema::table('account_receivables', function (Blueprint $table) {
            if (! $this->indexExists('account_receivables', 'ar_invoice_fiscal_sequence_unique')) {
                $table->unique(
                    ['invoice_id', 'fiscal_document_id', 'sequence_number'],
                    'ar_invoice_fiscal_sequence_unique'
                );
            }
        });
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function ensureIndex(string $tableName, array $columns, string $indexName): void
    {
        if ($this->indexExists($tableName, $indexName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($columns, $indexName) {
            $table->index($columns, $indexName);
        });
    }

    private function indexExists(string $tableName, string $indexName): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            return collect(DB::select("PRAGMA index_list('{$tableName}')"))
                ->contains(fn (object $index): bool => ($index->name ?? null) === $indexName);
        }

        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $tableName)
            ->where('INDEX_NAME', $indexName)
            ->exists();
    }
};
