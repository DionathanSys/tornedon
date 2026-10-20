<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commitment_billing_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('run_token');
            $table->boolean('emit_nfse')->default(false);
            $table->string('status')->default('queued');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
        Schema::table('commitment_billings', function (Blueprint $table) {
            $table->foreignId('active_batch_id')->nullable()->constrained('commitment_billing_batches')->restrictOnDelete();
            $table->uuid('execution_token')->nullable();
            $table->string('completed_step')->default('pending');
            $table->timestamp('processing_started_at')->nullable();
        });
        Schema::create('commitment_billing_batch_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('partners')->restrictOnDelete();
            $table->foreignId('commitment_billing_batch_id')->constrained(indexName: 'commitment_batch_item_batch_fk')->restrictOnDelete();
            $table->foreignId('customer_commitment_id')->constrained()->restrictOnDelete();
            $table->foreignId('commitment_billing_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('execution_token');
            $table->string('status')->default('queued');
            $table->text('last_error')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['commitment_billing_batch_id', 'commitment_billing_id'], 'commitment_batch_item_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commitment_billing_batch_items');
        Schema::table('commitment_billings', function (Blueprint $table) {
            $table->dropForeign(['active_batch_id']);
            $table->dropColumn(['active_batch_id', 'execution_token', 'completed_step', 'processing_started_at']);
        });
        Schema::dropIfExists('commitment_billing_batches');
    }
};
