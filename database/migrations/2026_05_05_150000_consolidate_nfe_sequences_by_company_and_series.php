<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $groups = DB::table('nfe_sequences')
                ->select('company_id', 'serie', DB::raw('MAX(last_number) as last_number'), DB::raw('MIN(id) as keep_id'))
                ->groupBy('company_id', 'serie')
                ->get();

            foreach ($groups as $group) {
                DB::table('nfe_sequences')
                    ->where('id', $group->keep_id)
                    ->update([
                        'last_number' => (int) $group->last_number,
                        'updated_at' => now(),
                    ]);

                $duplicateIds = DB::table('nfe_sequences')
                    ->where('company_id', $group->company_id)
                    ->where('serie', $group->serie)
                    ->where('id', '!=', $group->keep_id)
                    ->pluck('id');

                if ($duplicateIds->isEmpty()) {
                    continue;
                }

                DB::table('fiscal_documents')
                    ->whereIn('nfe_sequence_id', $duplicateIds)
                    ->update([
                        'nfe_sequence_id' => $group->keep_id,
                        'updated_at' => now(),
                    ]);

                DB::table('nfe_sequences')
                    ->whereIn('id', $duplicateIds)
                    ->delete();
            }
        });

        Schema::table('nfe_sequences', function (Blueprint $table) {
            if ($this->indexExists('nfe_sequences', 'nfe_seq_company_serie_nature_unique')) {
                $table->dropUnique('nfe_seq_company_serie_nature_unique');
            }

            if (! $this->indexExists('nfe_sequences', 'nfe_seq_company_serie_unique')) {
                $table->unique(['company_id', 'serie'], 'nfe_seq_company_serie_unique');
            }
        });
    }

    public function down(): void
    {
        Schema::table('nfe_sequences', function (Blueprint $table) {
            if ($this->indexExists('nfe_sequences', 'nfe_seq_company_serie_unique')) {
                $table->dropUnique('nfe_seq_company_serie_unique');
            }

            if (! $this->indexExists('nfe_sequences', 'nfe_seq_company_serie_nature_unique')) {
                $table->unique(['company_id', 'serie', 'operation_nature'], 'nfe_seq_company_serie_nature_unique');
            }
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'sqlite') {
            return DB::table('sqlite_master')
                ->where('type', 'index')
                ->where('tbl_name', $table)
                ->where('name', $index)
                ->exists();
        }

        $database = $connection->getDatabaseName();

        return DB::table('information_schema.statistics')
            ->where('table_schema', $database)
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }
};
