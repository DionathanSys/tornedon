<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table): void {
            $columns = array_values(array_filter([
                Schema::hasColumn('fiscal_documents', 'freight_data') ? 'freight_data' : null,
                Schema::hasColumn('fiscal_documents', 'payment_data') ? 'payment_data' : null,
                Schema::hasColumn('fiscal_documents', 'tax_data') ? 'tax_data' : null,
                Schema::hasColumn('fiscal_documents', 'nfe_payload') ? 'nfe_payload' : null,
                Schema::hasColumn('fiscal_documents', 'nfse_payload') ? 'nfse_payload' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table): void {
            if (! Schema::hasColumn('fiscal_documents', 'freight_data')) {
                $table->json('freight_data')->nullable();
            }

            if (! Schema::hasColumn('fiscal_documents', 'payment_data')) {
                $table->json('payment_data')->nullable();
            }

            if (! Schema::hasColumn('fiscal_documents', 'tax_data')) {
                $table->json('tax_data')->nullable();
            }

            if (! Schema::hasColumn('fiscal_documents', 'nfe_payload')) {
                $table->json('nfe_payload')->nullable();
            }

            if (! Schema::hasColumn('fiscal_documents', 'nfse_payload')) {
                $table->json('nfse_payload')->nullable();
            }
        });
    }
};
