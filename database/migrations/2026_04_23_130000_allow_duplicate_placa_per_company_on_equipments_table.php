<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('equipments') || ! $this->indexExists('equipments', 'equipments_placa_company_id_unique')) {
            return;
        }

        Schema::table('equipments', function (Blueprint $table) {
            $table->dropUnique('equipments_placa_company_id_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('equipments') || $this->indexExists('equipments', 'equipments_placa_company_id_unique')) {
            return;
        }

        Schema::table('equipments', function (Blueprint $table) {
            $table->unique(['placa', 'company_id']);
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            return DB::table('sqlite_master')
                ->where('type', 'index')
                ->where('tbl_name', $table)
                ->where('name', $index)
                ->exists();
        }

        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }
};
