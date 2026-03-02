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
        Schema::table('requisitions', function (Blueprint $table) {
            $table->foreignId('quote_id')
                ->nullable()
                ->after('service_order_id')
                ->constrained('quotes');

            $table->index('quote_id');
        });

        Schema::table('service_orders', function (Blueprint $table) {
            $table->foreignId('quote_id')
                ->nullable()
                ->after('company_id')
                ->constrained('quotes');

            $table->index('quote_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('requisitions', function (Blueprint $table) {
            $table->dropForeign(['quote_id']);
            $table->dropIndex(['quote_id']);
            $table->dropColumn('quote_id');
        });

        Schema::table('service_orders', function (Blueprint $table) {
            $table->dropForeign(['quote_id']);
            $table->dropIndex(['quote_id']);
            $table->dropColumn('quote_id');
        });
    }
};
