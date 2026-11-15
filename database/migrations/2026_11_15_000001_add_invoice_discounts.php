<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->bigInteger('invoice_discount_amount')->default(0);
            $table->string('invoice_discount_reason')->nullable();
            $table->string('invoice_discount_signature', 64)->nullable();
        });
        foreach (['requisition_items', 'service_order_items'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->bigInteger('invoice_discount_amount')->default(0);
                $table->decimal('invoice_discount_share_percentage', 12, 6)->default(0);
            });
        }
    }

    public function down(): void
    {
        foreach (['requisition_items', 'service_order_items'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn(['invoice_discount_amount', 'invoice_discount_share_percentage']));
        }
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn(['invoice_discount_amount', 'invoice_discount_reason', 'invoice_discount_signature']));
    }
};
