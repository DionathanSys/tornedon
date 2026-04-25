<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table) {
            $table->text('tax_observations')->nullable()->change();
            $table->text('additional_tax_information')->nullable()->change();
            $table->text('taxpayer_observations')->nullable()->change();
            $table->text('additional_taxpayer_information')->nullable()->change();
            $table->text('additional_purchase_information')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table) {
            $table->string('tax_observations')->nullable()->change();
            $table->string('additional_tax_information')->nullable()->change();
            $table->string('taxpayer_observations')->nullable()->change();
            $table->string('additional_taxpayer_information')->nullable()->change();
            $table->string('additional_purchase_information')->nullable()->change();
        });
    }
};
