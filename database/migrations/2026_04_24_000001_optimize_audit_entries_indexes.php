<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_entries', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'occurred_at']);
            $table->dropIndex(['auditable_type', 'auditable_id']);
            $table->dropIndex(['actor_user_id', 'occurred_at']);
            $table->dropIndex(['source', 'occurred_at']);
            $table->dropIndex(['action', 'occurred_at']);

            $table->index(['company_id', 'occurred_at'], 'audit_entries_company_occurred_idx');
            $table->index(['company_id', 'auditable_type', 'auditable_id', 'occurred_at'], 'audit_entries_company_auditable_idx');
            $table->index(['company_id', 'actor_user_id', 'occurred_at'], 'audit_entries_company_actor_idx');
            $table->index(['company_id', 'source', 'occurred_at'], 'audit_entries_company_source_idx');
            $table->index(['company_id', 'action', 'occurred_at'], 'audit_entries_company_action_idx');
        });
    }

    public function down(): void
    {
        Schema::table('audit_entries', function (Blueprint $table): void {
            $table->dropIndex('audit_entries_company_occurred_idx');
            $table->dropIndex('audit_entries_company_auditable_idx');
            $table->dropIndex('audit_entries_company_actor_idx');
            $table->dropIndex('audit_entries_company_source_idx');
            $table->dropIndex('audit_entries_company_action_idx');

            $table->index(['company_id', 'occurred_at']);
            $table->index(['auditable_type', 'auditable_id']);
            $table->index(['actor_user_id', 'occurred_at']);
            $table->index(['source', 'occurred_at']);
            $table->index(['action', 'occurred_at']);
        });
    }
};
