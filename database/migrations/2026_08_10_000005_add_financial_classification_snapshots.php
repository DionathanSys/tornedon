<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_payable_installments', function (Blueprint $table) {
            $table->date('competence_date')
                ->nullable()
                ->after('due_date');
            $table->foreignId('chart_account_id')
                ->nullable()
                ->after('bank_account_id')
                ->constrained('chart_accounts')
                ->nullOnDelete();
            $table->foreignId('result_center_id')
                ->nullable()
                ->after('cost_center_id')
                ->constrained('result_centers')
                ->nullOnDelete();
            $table->foreign('cost_center_id', 'api_cost_center_fk')
                ->references('id')
                ->on('cost_centers')
                ->nullOnDelete();
            $table->index(['company_id', 'competence_date'], 'api_company_competence_idx');
            $table->index(['company_id', 'chart_account_id'], 'api_company_chart_account_idx');
            $table->index(['company_id', 'cost_center_id'], 'api_company_cost_center_idx');
            $table->index(['company_id', 'result_center_id'], 'api_company_result_center_idx');
        });

        Schema::table('account_receivable_installments', function (Blueprint $table) {
            $table->date('competence_date')
                ->nullable()
                ->after('due_date');
            $table->foreignId('chart_account_id')
                ->nullable()
                ->after('bank_account_id')
                ->constrained('chart_accounts')
                ->nullOnDelete();
            $table->foreignId('result_center_id')
                ->nullable()
                ->after('cost_center_id')
                ->constrained('result_centers')
                ->nullOnDelete();
            $table->foreign('cost_center_id', 'ari_cost_center_fk')
                ->references('id')
                ->on('cost_centers')
                ->nullOnDelete();
            $table->index(['company_id', 'competence_date'], 'ari_company_competence_idx');
            $table->index(['company_id', 'chart_account_id'], 'ari_company_chart_account_idx');
            $table->index(['company_id', 'cost_center_id'], 'ari_company_cost_center_idx');
            $table->index(['company_id', 'result_center_id'], 'ari_company_result_center_idx');
        });

        Schema::table('cash_movements', function (Blueprint $table) {
            $table->date('competence_date')
                ->nullable()
                ->after('transaction_date');
            $table->foreignId('chart_account_id')
                ->nullable()
                ->after('financial_category_id')
                ->constrained('chart_accounts')
                ->nullOnDelete();
            $table->foreignId('cost_center_id')
                ->nullable()
                ->after('chart_account_id')
                ->constrained('cost_centers')
                ->nullOnDelete();
            $table->foreignId('result_center_id')
                ->nullable()
                ->after('cost_center_id')
                ->constrained('result_centers')
                ->nullOnDelete();
            $table->index(['company_id', 'competence_date']);
            $table->index(['company_id', 'chart_account_id']);
            $table->index(['company_id', 'cost_center_id']);
            $table->index(['company_id', 'result_center_id']);
        });
    }

    public function down(): void
    {
        Schema::table('cash_movements', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'result_center_id']);
            $table->dropIndex(['company_id', 'cost_center_id']);
            $table->dropIndex(['company_id', 'chart_account_id']);
            $table->dropIndex(['company_id', 'competence_date']);
            $table->dropConstrainedForeignId('result_center_id');
            $table->dropConstrainedForeignId('cost_center_id');
            $table->dropConstrainedForeignId('chart_account_id');
            $table->dropColumn('competence_date');
        });

        Schema::table('account_receivable_installments', function (Blueprint $table) {
            $table->dropIndex('ari_company_result_center_idx');
            $table->dropIndex('ari_company_cost_center_idx');
            $table->dropIndex('ari_company_chart_account_idx');
            $table->dropIndex('ari_company_competence_idx');
            $table->dropForeign('ari_cost_center_fk');
            $table->dropConstrainedForeignId('result_center_id');
            $table->dropConstrainedForeignId('chart_account_id');
            $table->dropColumn('competence_date');
        });

        Schema::table('account_payable_installments', function (Blueprint $table) {
            $table->dropIndex('api_company_result_center_idx');
            $table->dropIndex('api_company_cost_center_idx');
            $table->dropIndex('api_company_chart_account_idx');
            $table->dropIndex('api_company_competence_idx');
            $table->dropForeign('api_cost_center_fk');
            $table->dropConstrainedForeignId('result_center_id');
            $table->dropConstrainedForeignId('chart_account_id');
            $table->dropColumn('competence_date');
        });
    }
};
