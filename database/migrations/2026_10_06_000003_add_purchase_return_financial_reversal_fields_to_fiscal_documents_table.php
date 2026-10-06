<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table): void {
            $table->timestamp('return_financial_reversed_at')
                ->nullable()
                ->after('return_financial_processed_at');
            $table->foreignId('return_financial_reversed_by')
                ->nullable()
                ->after('return_financial_reversed_at')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('return_financial_reversed_by');
            $table->dropColumn('return_financial_reversed_at');
        });
    }
};
