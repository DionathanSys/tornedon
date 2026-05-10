<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table) {
            $table->json('return_financial_data')
                ->nullable()
                ->after('tax_data');
            $table->timestamp('return_financial_processed_at')
                ->nullable()
                ->after('return_financial_data');
            $table->foreignId('return_financial_processed_by')
                ->nullable()
                ->after('return_financial_processed_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('return_stock_processed_at')
                ->nullable()
                ->after('return_financial_processed_by');
            $table->foreignId('return_stock_processed_by')
                ->nullable()
                ->after('return_stock_processed_at')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('return_stock_processed_by');
            $table->dropColumn('return_stock_processed_at');
            $table->dropConstrainedForeignId('return_financial_processed_by');
            $table->dropColumn('return_financial_processed_at');
            $table->dropColumn('return_financial_data');
        });
    }
};
