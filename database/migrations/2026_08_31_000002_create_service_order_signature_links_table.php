<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_order_signature_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('service_order_id')
                ->constrained('service_orders')
                ->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();

            $table->index(['service_order_id', 'expires_at']);
            $table->index(['service_order_id', 'used_at', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_order_signature_links');
    }
};
