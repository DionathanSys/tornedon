<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('email_dispatches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();
            $table->foreignId('company_partner_id')
                ->nullable()
                ->constrained('company_partner')
                ->nullOnDelete();

            $table->string('document_type', 40);
            $table->unsignedBigInteger('document_id');
            $table->string('event', 40);
            $table->string('status', 32);

            $table->json('to')->nullable();
            $table->json('cc')->nullable();
            $table->json('bcc')->nullable();
            $table->string('subject')->nullable();

            $table->json('attachments_manifest')->nullable();
            $table->string('attachments_hash', 64)->nullable();

            $table->string('idempotency_key', 191);

            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(5);

            $table->string('provider', 80)->nullable();
            $table->string('provider_message_id')->nullable();
            $table->json('provider_payload')->nullable();

            $table->text('error_message')->nullable();
            $table->dateTime('last_error_at')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'document_type', 'document_id', 'event'], 'email_dispatch_idempotency_unique');
            $table->index(['status', 'created_at'], 'email_dispatch_status_created_idx');
            $table->index(['company_id', 'status'], 'email_dispatch_company_status_idx');
            $table->index('idempotency_key', 'email_dispatch_idempotency_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_dispatches');
    }
};

