<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_statement_lines', function (Blueprint $table) {
            $table->string('transaction_key')
                ->nullable()
                ->after('external_id');
            $table->foreignId('last_seen_import_run_id')
                ->nullable()
                ->after('reconciled_at')
                ->constrained('bank_statement_import_runs')
                ->nullOnDelete();
            $table->char('source_payload_hash', 64)
                ->nullable()
                ->after('last_seen_import_run_id');
            $table->timestamp('needs_review_at')
                ->nullable()
                ->after('source_payload_hash');
            $table->text('review_reason')
                ->nullable()
                ->after('needs_review_at');
            $table->unique(['bank_statement_import_id', 'transaction_key'], 'bsl_import_transaction_key_unique');
            $table->index('last_seen_import_run_id', 'bsl_last_seen_run_idx');
            $table->index('needs_review_at', 'bsl_needs_review_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('bank_statement_lines', function (Blueprint $table) {
            $table->dropIndex('bsl_needs_review_at_idx');
            $table->dropIndex('bsl_last_seen_run_idx');
            $table->dropUnique('bsl_import_transaction_key_unique');
            $table->dropConstrainedForeignId('last_seen_import_run_id');
            $table->dropColumn([
                'transaction_key',
                'source_payload_hash',
                'needs_review_at',
                'review_reason',
            ]);
        });
    }
};
