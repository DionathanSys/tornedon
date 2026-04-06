<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if ($this->indexExists('invoices', 'invoices_invoice_number_unique')) {
                $table->dropUnique('invoices_invoice_number_unique');
            }

            if (! $this->indexExists('invoices', 'invoices_company_id_invoice_number_unique')) {
                $table->unique(['company_id', 'invoice_number']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if ($this->indexExists('invoices', 'invoices_company_id_invoice_number_unique')) {
                $table->dropUnique('invoices_company_id_invoice_number_unique');
            }

            if (! $this->indexExists('invoices', 'invoices_invoice_number_unique')) {
                $table->unique(['invoice_number']);
            }
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        $driver = DB::getDriverName();

        return match ($driver) {
            'sqlite' => collect(DB::select("PRAGMA index_list('{$table}')"))
                ->pluck('name')
                ->contains($index),
            'mysql' => collect(DB::select(
                'SHOW INDEX FROM `' . $table . '` WHERE Key_name = ?',
                [$index]
            ))->isNotEmpty(),
            default => false,
        };
    }
};
