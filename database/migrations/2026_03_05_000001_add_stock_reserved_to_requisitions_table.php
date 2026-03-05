<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requisitions', function (Blueprint $table) {
            // null  = aberta, nunca foi fechada (listeners gerenciam reservas por item)
            // true  = fechada com reservas ativamente em vigor no estoque
            // false = foi reaberta, reservas liberadas (recriar no próximo fechamento)
            $table->boolean('stock_reserved')->nullable()->default(null)->after('stock_consumed');
        });
    }

    public function down(): void
    {
        Schema::table('requisitions', function (Blueprint $table) {
            $table->dropColumn('stock_reserved');
        });
    }
};
