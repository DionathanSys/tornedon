<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->string('name');
            $table->string('document_type', 20);
            $table->string('operation_nature')->nullable();
            $table->boolean('stock_control_enabled')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('additional_information')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['company_id', 'name']);
        });
        Schema::create('bpo_sales', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->uuid('request_id');
            $table->foreignId('sale_template_id')->constrained()->restrictOnDelete();
            $table->json('template_snapshot');
            $table->foreignId('service_order_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('requisition_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->restrictOnDelete();
            $table->json('payment_settings')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->unique(['company_id', 'request_id']);
            $table->unique('invoice_id');
        });
        Schema::table('requisitions', function (Blueprint $table): void {
            $table->boolean('stock_control_enabled')->default(true);
        });
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('financial_policy', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bpo_sales');
        Schema::dropIfExists('sale_templates');
        Schema::table('requisitions', fn (Blueprint $table) => $table->dropColumn('stock_control_enabled'));
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn('financial_policy'));
    }
};
