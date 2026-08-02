<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fiscal_rules')) {
            return;
        }

        Schema::table('fiscal_rules', function (Blueprint $table): void {
            if (! Schema::hasColumn('fiscal_rules', 'cst_ibs_cbs')) {
                $table->string('cst_ibs_cbs', 3)->nullable()->after('cst_ipi');
            }

            if (! Schema::hasColumn('fiscal_rules', 'classificacao_tributaria_ibs_cbs')) {
                $table->string('classificacao_tributaria_ibs_cbs', 6)->nullable()->after('cst_ibs_cbs');
            }

            if (! Schema::hasColumn('fiscal_rules', 'indicador_doacao_ibs_cbs')) {
                $table->string('indicador_doacao_ibs_cbs', 1)->nullable()->after('classificacao_tributaria_ibs_cbs');
            }

            if (! Schema::hasColumn('fiscal_rules', 'aliquota_ibs_estadual')) {
                $table->decimal('aliquota_ibs_estadual', 8, 4)->nullable()->after('aliquota_ipi');
            }

            if (! Schema::hasColumn('fiscal_rules', 'aliquota_ibs_municipal')) {
                $table->decimal('aliquota_ibs_municipal', 8, 4)->nullable()->after('aliquota_ibs_estadual');
            }

            if (! Schema::hasColumn('fiscal_rules', 'aliquota_cbs')) {
                $table->decimal('aliquota_cbs', 8, 4)->nullable()->after('aliquota_ibs_municipal');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('fiscal_rules')) {
            return;
        }

        Schema::table('fiscal_rules', function (Blueprint $table): void {
            foreach ([
                'aliquota_cbs',
                'aliquota_ibs_municipal',
                'aliquota_ibs_estadual',
                'indicador_doacao_ibs_cbs',
                'classificacao_tributaria_ibs_cbs',
                'cst_ibs_cbs',
            ] as $column) {
                if (Schema::hasColumn('fiscal_rules', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
