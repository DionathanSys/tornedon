<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_requests', function (Blueprint $table) {
            $table->id();
            $table->string('number')->nullable();
            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();
            $table->foreignId('customer_id')
                ->nullable()
                ->constrained('partners')
                ->nullOnDelete();
            $table->string('manual_counterparty_name')->nullable();
            $table->string('status')->default('open');
            $table->date('order_date');
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('payment_condition')->nullable();
            $table->foreignId('financial_category_id')
                ->nullable()
                ->constrained('financial_categories')
                ->nullOnDelete();
            $table->foreignId('account_receivable_id')
                ->nullable()
                ->constrained('account_receivables')
                ->nullOnDelete();
            $table->text('observations')->nullable();
            $table->json('additional_info')->nullable();
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'order_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_requests');
    }
};
