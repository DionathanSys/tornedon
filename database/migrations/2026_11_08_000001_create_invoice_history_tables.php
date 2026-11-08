<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('cancellation_reason')->nullable();
        });

        Schema::create('invoice_confirmations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->json('amounts');
            $table->json('commercial_data');
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at');
            $table->unique(['invoice_id', 'revision']);
        });

        Schema::create('invoice_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_confirmation_id')->nullable()->constrained()->restrictOnDelete();
            // Stable aliases, not PHP class names; the original record may later change.
            $table->string('source_type', 32);
            $table->unsignedBigInteger('source_id');
            $table->string('source_number')->nullable();
            $table->json('snapshot');
            $table->timestamp('linked_at');
            $table->timestamp('released_at')->nullable();
            $table->string('release_reason')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['company_id', 'source_type', 'source_id']);
            $table->unique(['invoice_confirmation_id', 'source_type', 'source_id'], 'invoice_sources_confirmation_source_unique');
        });

        Schema::create('invoice_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->string('type');
            $table->string('status');
            // NULL for terminal operations; unique slot serializes active operations.
            $table->unsignedTinyInteger('active_slot')->nullable();
            $table->uuid('request_id')->unique();
            $table->text('justification');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['invoice_id', 'active_slot']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_operations');
        Schema::dropIfExists('invoice_sources');
        Schema::dropIfExists('invoice_confirmations');
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn('cancellation_reason'));
    }
};
