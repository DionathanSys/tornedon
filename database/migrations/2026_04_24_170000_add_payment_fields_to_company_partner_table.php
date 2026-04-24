<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_partner', function (Blueprint $table) {
            $table->string('payment_method')->nullable()->after('customer_discount_percentage');
            $table->string('payment_condition')->nullable()->after('payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('company_partner', function (Blueprint $table) {
            $table->dropColumn([
                'payment_method',
                'payment_condition',
            ]);
        });
    }
};
