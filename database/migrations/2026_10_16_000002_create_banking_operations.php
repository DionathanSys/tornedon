<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['bank_slips', 'pix_charges'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->string('operation_status')->nullable()->index();
                $table->string('operation_kind')->nullable();
                $table->uuid('operation_attempt_id')->nullable();
                $table->json('pending_update')->nullable();
                $table->string('provider_operation_id')->nullable();
                $table->string('provider_response_code')->nullable();
                $table->json('request_snapshot')->nullable();
                $table->timestamp('request_sent_at')->nullable();
            });
        }
        Schema::create('banking_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('bank_account_connection_id')->constrained()->restrictOnDelete();
            $table->foreignId('bank_slip_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('pix_charge_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('event_key', 64);
            $table->string('source', 30);
            $table->string('operation', 30);
            $table->string('status')->default('pending')->index();
            $table->json('payload');
            $table->text('error')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('deliveries')->default(1);
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('last_received_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['bank_account_connection_id', 'event_key'], 'banking_events_connection_key_unique');
            $table->index(['company_id', 'status']);
            $table->index(['bank_slip_id', 'status']);
            $table->index(['pix_charge_id', 'status']);
        });
        Schema::table('account_receivable_installment_payments', function (Blueprint $table): void {
            $table->foreignId('banking_event_id')->nullable()->unique()->constrained('banking_events')->restrictOnDelete();
            $table->decimal('returned_amount', 15, 4)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('account_receivable_installment_payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('banking_event_id');
            $table->dropColumn('returned_amount');
        });
        Schema::dropIfExists('banking_events');
        foreach (['bank_slips', 'pix_charges'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn([
                'operation_status', 'operation_kind', 'operation_attempt_id', 'pending_update', 'provider_operation_id', 'provider_response_code', 'request_snapshot', 'request_sent_at',
            ]));
        }
    }
};
