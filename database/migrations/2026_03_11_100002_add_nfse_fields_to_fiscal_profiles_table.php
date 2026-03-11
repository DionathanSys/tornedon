<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_profiles', function (Blueprint $table) {
            $table->decimal('iss_rate_default', 5, 2)->nullable()->after('ipi_enquadramento');
            $table->boolean('iss_withheld_default')->default(false)->after('iss_rate_default');
            $table->string('nfse_special_tax_regime', 2)->nullable()->after('iss_withheld_default');
            $table->string('default_service_code', 10)->nullable()->after('nfse_special_tax_regime');
            $table->string('service_cnae_code', 7)->nullable()->after('default_service_code');
            $table->string('default_nbs_code', 9)->nullable()->after('service_cnae_code');
            $table->string('default_municipal_tax_code', 20)->nullable()->after('default_nbs_code');
            $table->text('default_nfse_additional_information')->nullable()->after('default_municipal_tax_code');
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'iss_rate_default',
                'iss_withheld_default',
                'nfse_special_tax_regime',
                'default_service_code',
                'service_cnae_code',
                'default_nbs_code',
                'default_municipal_tax_code',
                'default_nfse_additional_information',
            ]);
        });
    }
};
