<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fiscal_profiles') || Schema::hasColumn('fiscal_profiles', 'default_service_city_code')) {
            return;
        }

        Schema::table('fiscal_profiles', function (Blueprint $table) {
            $table->string('default_service_city_code', 7)
                ->nullable()
                ->after('default_municipal_tax_code');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('fiscal_profiles') || ! Schema::hasColumn('fiscal_profiles', 'default_service_city_code')) {
            return;
        }

        Schema::table('fiscal_profiles', function (Blueprint $table) {
            $table->dropColumn('default_service_city_code');
        });
    }
};
