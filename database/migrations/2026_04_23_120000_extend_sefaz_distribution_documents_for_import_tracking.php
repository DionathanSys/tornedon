<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sefaz_distribution_documents', function (Blueprint $table): void {
            $table->foreignId('fiscal_document_id')
                ->nullable()
                ->after('partner_id')
                ->constrained('fiscal_documents')
                ->nullOnDelete();
            $table->string('import_status')
                ->default('pending_xml')
                ->after('full_xml_available');
            $table->text('import_error')
                ->nullable()
                ->after('import_status');
            $table->timestamp('import_attempted_at')
                ->nullable()
                ->after('import_error');
            $table->foreignId('imported_by')
                ->nullable()
                ->after('imported_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('ignored_at')
                ->nullable()
                ->after('imported_by');
            $table->foreignId('ignored_by')
                ->nullable()
                ->after('ignored_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->string('ignore_reason')
                ->nullable()
                ->after('ignored_by');
            $table->string('last_action')
                ->nullable()
                ->after('ignore_reason');
            $table->timestamp('last_action_at')
                ->nullable()
                ->after('last_action');
            $table->string('last_error_code')
                ->nullable()
                ->after('last_action_at');
            $table->text('last_error_message')
                ->nullable()
                ->after('last_error_code');
            $table->string('last_job_uuid')
                ->nullable()
                ->after('last_error_message');

            $table->index(['company_id', 'import_status'], 'sdd_company_import_status_idx');
            $table->index(['company_id', 'fiscal_document_id'], 'sdd_company_fiscal_document_idx');
        });
    }

    public function down(): void
    {
        Schema::table('sefaz_distribution_documents', function (Blueprint $table): void {
            $table->dropIndex('sdd_company_import_status_idx');
            $table->dropIndex('sdd_company_fiscal_document_idx');
            $table->dropConstrainedForeignId('fiscal_document_id');
            $table->dropConstrainedForeignId('imported_by');
            $table->dropConstrainedForeignId('ignored_by');
            $table->dropColumn([
                'import_status',
                'import_error',
                'import_attempted_at',
                'ignored_at',
                'ignore_reason',
                'last_action',
                'last_action_at',
                'last_error_code',
                'last_error_message',
                'last_job_uuid',
            ]);
        });
    }
};
