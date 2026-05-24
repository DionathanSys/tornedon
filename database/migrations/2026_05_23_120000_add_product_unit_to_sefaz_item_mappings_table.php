<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sefaz_item_mappings', function (Blueprint $table): void {
            $table->string('product_unit', 10)
                ->nullable()
                ->after('product_id');
        });
    }

    public function down(): void
    {
        Schema::table('sefaz_item_mappings', function (Blueprint $table): void {
            $table->dropColumn('product_unit');
        });
    }
};
