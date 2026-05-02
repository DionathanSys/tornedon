<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_closings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();
            $table->foreignId('supplier_id')
                ->constrained('partners');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('reference', 100)
                ->nullable();
            $table->string('status', 30)
                ->default('draft');
            $table->text('notes')
                ->nullable();
            $table->decimal('gross_amount', 15, 4)
                ->default(0);
            $table->decimal('discount_amount', 15, 4)
                ->default(0);
            $table->decimal('net_amount', 15, 4)
                ->virtualAs('gross_amount - discount_amount');
            $table->foreignId('account_payable_id')
                ->nullable()
                ->constrained('account_payables')
                ->nullOnDelete();
            $table->timestamp('closed_at')
                ->nullable();
            $table->foreignId('closed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('reopened_at')
                ->nullable();
            $table->foreignId('reopened_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'supplier_id', 'status'], 'purchase_closings_company_supplier_status_idx');
            $table->index(['company_id', 'start_date', 'end_date'], 'purchase_closings_company_period_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_closings');
    }
};
