<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warranty_claims', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('number', 50);
            $table->string('type', 50);
            $table->string('status', 50);
            $table->foreignId('customer_id')->constrained('partners')->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('partners')->nullOnDelete();
            $table->foreignId('service_order_id')->nullable()->constrained('service_orders')->nullOnDelete();
            $table->foreignId('origin_service_order_id')->nullable()->constrained('service_orders')->nullOnDelete();
            $table->foreignId('origin_requisition_id')->nullable()->constrained('requisitions')->nullOnDelete();
            $table->foreignId('origin_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('origin_fiscal_document_id')->nullable()->constrained('fiscal_documents')->nullOnDelete();
            $table->foreignId('remittance_fiscal_document_id')->nullable()->constrained('fiscal_documents')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('equipment_id')->nullable()->constrained('equipments')->nullOnDelete();
            $table->decimal('quantity', 15, 4)->default(1);
            $table->string('serial_number')->nullable();
            $table->string('lot_number')->nullable();
            $table->date('expires_at')->nullable();
            $table->string('coverage_type', 50);
            $table->string('responsibility', 50);
            $table->text('customer_issue_description');
            $table->text('technical_diagnosis')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->string('supplier_protocol')->nullable();
            $table->boolean('advanced_replacement')->default(false);
            $table->string('supplier_decision', 50)->default('pending');
            $table->string('supplier_resolution', 50)->default('none');
            $table->timestamp('sent_to_supplier_at')->nullable();
            $table->timestamp('returned_from_supplier_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'number'], 'warranty_claims_company_number_unique');
            $table->index(['company_id', 'type', 'status'], 'warranty_claims_company_type_status_idx');
            $table->index(['company_id', 'customer_id'], 'warranty_claims_company_customer_idx');
            $table->index(['company_id', 'supplier_id'], 'warranty_claims_company_supplier_idx');
            $table->index(['company_id', 'product_id'], 'warranty_claims_company_product_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warranty_claims');
    }
};
