<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_profiles', function (Blueprint $table) {
            $table->string('nfse_nacional_regime_apuracao', 5)->nullable()->after('nfse_special_tax_regime');

            $table->string('nfse_nacional_cst_default', 10)->nullable()->after('nfse_nacional_regime_apuracao');
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'nfse_nacional_regime_apuracao',
                'nfse_nacional_cst_default',
            ]);
        });
    }
};
