<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            $table->decimal('value_km', 15, 2)
                ->nullable()
                ->after('actual_hours');
            $table->decimal('distance_km', 15, 2)
                ->nullable()
                ->after('value_km');
        });
    }

    public function down(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            $table->dropColumn([
                'value_km',
                'distance_km',
            ]);
        });
    }
};
