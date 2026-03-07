<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            $table->dropColumn('discount_amount');
        });
    }

    public function down(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            $table->decimal('discount_amount', 15, 2)->default(0.00)->after('travel_value');
        });
    }
};
