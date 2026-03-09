<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_document_items', function (Blueprint $table) {
            $table->renameColumn('origin_code', 'product_origin');
        });

        Schema::table('fiscal_document_items', function (Blueprint $table) {
            $table->string('product_code', 60)->nullable()->after('product_id');
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_document_items', function (Blueprint $table) {
            $table->dropColumn('product_code');
        });

        Schema::table('fiscal_document_items', function (Blueprint $table) {
            $table->renameColumn('product_origin', 'origin_code');
        });
    }
};
