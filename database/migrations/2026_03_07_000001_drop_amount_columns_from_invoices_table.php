<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['total_amount', 'discount_amount']);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('total_amount', 15, 4)->default(0)->after('invoice_date');
            $table->decimal('discount_amount', 15, 4)->default(0)->after('total_amount');
        });
    }
};
