<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_taxes', function (Blueprint $table) {
            $table->dropColumn('cfop_code');
        });
    }

    public function down(): void
    {
        Schema::table('product_taxes', function (Blueprint $table) {
            $table->string('cfop_code', 4)->nullable()->after('cest_code');
        });
    }
};
