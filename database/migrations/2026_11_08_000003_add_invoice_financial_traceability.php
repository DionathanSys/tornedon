<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_receivable_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('account_receivable_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('source_receivable_id');
            $table->string('origin');
            $table->json('original_links');
            $table->json('snapshot');
            $table->unsignedTinyInteger('active_slot')->nullable();
            $table->foreignId('linked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('linked_at');
            $table->timestamp('released_at')->nullable();
            $table->string('release_reason')->nullable();
            $table->json('release_snapshot')->nullable();
            $table->unique(['account_receivable_id', 'active_slot'], 'invoice_receivable_one_active_link');
        });
        Schema::table('invoice_operations', function (Blueprint $table): void {
            $table->json('steps')->nullable();
            $table->text('last_message')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('last_run_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_operations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('last_run_by');
            $table->dropColumn(['steps', 'last_message', 'completed_at']);
        });
        Schema::dropIfExists('invoice_receivable_links');
    }
};
