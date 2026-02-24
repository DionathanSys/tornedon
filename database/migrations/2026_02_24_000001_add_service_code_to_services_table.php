<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->string('service_code', 20)
                ->nullable()
                ->after('id');

            $table->unique(['company_id', 'service_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            // Cria um índice para company_id antes de dropar o composto, 
            // pois a FK de company_id no MySQL exige um índice que comece com ela.
            $table->index('company_id');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'service_code']);
            $table->dropColumn('service_code');
        });
    }
};
