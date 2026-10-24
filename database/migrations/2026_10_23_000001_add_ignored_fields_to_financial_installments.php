<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['account_payable_installments', 'account_receivable_installments'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->timestamp('ignored_at')->nullable()->index();
                $table->foreignId('ignored_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('ignored_reason')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['account_payable_installments', 'account_receivable_installments'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('ignored_by');
                $table->dropIndex(['ignored_at']);
                $table->dropColumn(['ignored_at', 'ignored_reason']);
            });
        }
    }
};
