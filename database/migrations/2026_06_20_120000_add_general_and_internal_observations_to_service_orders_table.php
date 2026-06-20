<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            $table->text('general_observations')
                ->nullable()
                ->after('customer_observations');

            $table->text('internal_observations')
                ->nullable()
                ->after('general_observations');
        });
    }

    public function down(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            $table->dropColumn(['general_observations', 'internal_observations']);
        });
    }
};
