<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_document_items', function (Blueprint $table) {
            $table->string('iss_exigibility', 2)->nullable()->after('fiscal_snapshot');
            $table->decimal('iss_rate', 5, 4)->nullable()->after('iss_exigibility');
            $table->decimal('iss_amount', 15, 2)->nullable()->after('iss_rate');
            $table->boolean('iss_withheld')->nullable()->after('iss_amount');
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_document_items', function (Blueprint $table) {
            $table->dropColumn([
                'iss_exigibility',
                'iss_rate',
                'iss_amount',
                'iss_withheld',
            ]);
        });
    }
};
