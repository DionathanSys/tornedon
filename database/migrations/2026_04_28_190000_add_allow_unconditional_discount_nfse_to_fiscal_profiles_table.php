<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_profiles', function (Blueprint $table) {
            $table->boolean('allow_unconditional_discount_nfse')
                ->default(false)
                ->after('iss_withheld_default');
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_profiles', function (Blueprint $table) {
            $table->dropColumn('allow_unconditional_discount_nfse');
        });
    }
};
