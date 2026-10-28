<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_people', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_partner_id')->constrained('company_partner')->restrictOnDelete();
            $table->json('roles');
            $table->string('position')->nullable();
            $table->date('started_at')->nullable();
            $table->date('ended_at')->nullable();
            $table->decimal('base_amount', 15, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('financial_category_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('chart_account_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('cost_center_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('result_center_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'company_partner_id']);
        });
        Schema::create('hr_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('hr_person_id')->constrained('hr_people')->restrictOnDelete();
            $table->date('competence_date');
            $table->date('due_date');
            $table->string('nature', 30);
            $table->string('status', 20)->default('draft');
            $table->json('items');
            $table->json('advance_deductions')->nullable();
            $table->decimal('gross_amount', 15, 2);
            $table->decimal('deduction_amount', 15, 2);
            $table->decimal('net_amount', 15, 2);
            $table->text('notes')->nullable();
            $table->foreignId('financial_category_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('chart_account_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('cost_center_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('result_center_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('account_payable_id')->nullable()->unique()->constrained('account_payables')->restrictOnDelete();
            $table->json('snapshot')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'competence_date', 'nature']);
        });
        Schema::create('hr_advance_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hr_entry_id')->constrained('hr_entries')->restrictOnDelete();
            $table->foreignId('advance_entry_id')->constrained('hr_entries')->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->timestamps();
            $table->unique(['hr_entry_id', 'advance_entry_id']);
        });
        Schema::create('hr_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('hr_entry_id')->constrained('hr_entries')->restrictOnDelete();
            // Keep the original payment reference after reversal/deletion for audit history.
            $table->unsignedBigInteger('payment_id');
            $table->string('fingerprint', 64);
            $table->timestamp('voided_at')->nullable();
            $table->json('snapshot');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['payment_id', 'fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_receipts');
        Schema::dropIfExists('hr_advance_allocations');
        Schema::dropIfExists('hr_entries');
        Schema::dropIfExists('hr_people');
    }
};
