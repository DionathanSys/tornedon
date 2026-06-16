<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_payables', function (Blueprint $table) {
            $table->dropForeign(['supplier_id']);
            $table->unsignedBigInteger('supplier_id')->nullable()->change();
            $table->foreign('supplier_id')
                ->references('id')
                ->on('partners')
                ->nullOnDelete();

            $table->string('manual_counterparty_name')->nullable()->after('supplier_id');
        });

        Schema::table('account_receivables', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->unsignedBigInteger('customer_id')->nullable()->change();
            $table->foreign('customer_id')
                ->references('id')
                ->on('partners')
                ->nullOnDelete();

            $table->string('manual_counterparty_name')->nullable()->after('customer_id');

            $table->dropForeign(['invoice_id']);
            $table->unsignedBigInteger('invoice_id')->nullable()->change();
            $table->foreign('invoice_id')
                ->references('id')
                ->on('invoices')
                ->nullOnDelete();
        });

        Schema::table('cash_movements', function (Blueprint $table) {
            $table->string('manual_counterparty_name')->nullable()->after('counterparty_partner_id');
        });
    }

    public function down(): void
    {
        Schema::table('cash_movements', function (Blueprint $table) {
            $table->dropColumn('manual_counterparty_name');
        });

        Schema::table('account_receivables', function (Blueprint $table) {
            $table->dropForeign(['invoice_id']);
            $table->unsignedBigInteger('invoice_id')->nullable(false)->change();
            $table->foreign('invoice_id')
                ->references('id')
                ->on('invoices')
                ->cascadeOnDelete();

            $table->dropForeign(['customer_id']);
            $table->unsignedBigInteger('customer_id')->nullable(false)->change();
            $table->foreign('customer_id')
                ->references('id')
                ->on('partners')
                ->cascadeOnDelete();

            $table->dropColumn('manual_counterparty_name');
        });

        Schema::table('account_payables', function (Blueprint $table) {
            $table->dropForeign(['supplier_id']);
            $table->unsignedBigInteger('supplier_id')->nullable(false)->change();
            $table->foreign('supplier_id')
                ->references('id')
                ->on('partners')
                ->cascadeOnDelete();

            $table->dropColumn('manual_counterparty_name');
        });
    }
};
