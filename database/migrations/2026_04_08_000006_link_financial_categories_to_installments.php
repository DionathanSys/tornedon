<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_payable_installments', function (Blueprint $table) {
            $table->foreign('financial_category_id', 'api_financial_category_fk')
                ->references('id')
                ->on('financial_categories')
                ->nullOnDelete();
        });

        Schema::table('account_receivable_installments', function (Blueprint $table) {
            $table->foreign('financial_category_id', 'ari_financial_category_fk')
                ->references('id')
                ->on('financial_categories')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('account_receivable_installments', function (Blueprint $table) {
            $table->dropForeign('ari_financial_category_fk');
        });

        Schema::table('account_payable_installments', function (Blueprint $table) {
            $table->dropForeign('api_financial_category_fk');
        });
    }
};
