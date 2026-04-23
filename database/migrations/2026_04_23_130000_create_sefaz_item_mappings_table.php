<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sefaz_item_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('partner_id')->constrained('partners')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('xml_item_code', 60);
            $table->string('xml_barcode', 60)->nullable();
            $table->string('xml_description')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'partner_id', 'xml_item_code'], 'sim_company_partner_xml_code_unique');
            $table->index(['company_id', 'partner_id'], 'sim_company_partner_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sefaz_item_mappings');
    }
};
