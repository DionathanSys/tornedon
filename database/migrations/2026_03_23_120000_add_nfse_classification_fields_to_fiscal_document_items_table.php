<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_document_items', function (Blueprint $table) {
            $table->string('municipal_tax_code', 20)->nullable()->after('service_id');
            $table->string('nbs_code', 9)->nullable()->after('municipal_tax_code');
            $table->string('cnae_code', 7)->nullable()->after('nbs_code');
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_document_items', function (Blueprint $table) {
            $table->dropColumn([
                'municipal_tax_code',
                'nbs_code',
                'cnae_code',
            ]);
        });
    }
};
