<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fiscal_profiles')) {
            return;
        }

        Schema::table('fiscal_profiles', function (Blueprint $table) {
            if (! Schema::hasColumn('fiscal_profiles', 'additional_tax_information_default')) {
                $table->text('additional_tax_information_default')->nullable()->after('cfop_rules');
            }

            if (! Schema::hasColumn('fiscal_profiles', 'additional_taxpayer_information_default')) {
                $table->text('additional_taxpayer_information_default')->nullable()->after('additional_tax_information_default');
            }

            if (! Schema::hasColumn('fiscal_profiles', 'additional_purchase_information_default')) {
                $table->json('additional_purchase_information_default')->nullable()->after('additional_taxpayer_information_default');
            }

            if (! Schema::hasColumn('fiscal_profiles', 'taxpayer_observations_default')) {
                $table->json('taxpayer_observations_default')->nullable()->after('additional_purchase_information_default');
            }

            if (! Schema::hasColumn('fiscal_profiles', 'tax_observations_default')) {
                $table->json('tax_observations_default')->nullable()->after('taxpayer_observations_default');
            }
        });

        if (Schema::hasColumn('fiscal_profiles', 'informacoes_adicionais_fisco')) {
            DB::statement('UPDATE fiscal_profiles SET additional_tax_information_default = COALESCE(additional_tax_information_default, informacoes_adicionais_fisco)');
        }

        if (Schema::hasColumn('fiscal_profiles', 'informacoes_adicionais_contribuinte')) {
            DB::statement('UPDATE fiscal_profiles SET additional_taxpayer_information_default = COALESCE(additional_taxpayer_information_default, informacoes_adicionais_contribuinte)');
        }

        if (Schema::hasColumn('fiscal_profiles', 'informacoes_adicionais_compra')) {
            DB::statement('UPDATE fiscal_profiles SET additional_purchase_information_default = COALESCE(additional_purchase_information_default, informacoes_adicionais_compra)');
        }

        if (Schema::hasColumn('fiscal_profiles', 'observacoes_contribuinte')) {
            DB::statement('UPDATE fiscal_profiles SET taxpayer_observations_default = COALESCE(taxpayer_observations_default, observacoes_contribuinte)');
        }

        if (Schema::hasColumn('fiscal_profiles', 'observacoes_fisco')) {
            DB::statement('UPDATE fiscal_profiles SET tax_observations_default = COALESCE(tax_observations_default, observacoes_fisco)');
        }

        Schema::table('fiscal_profiles', function (Blueprint $table) {
            if (Schema::hasColumn('fiscal_profiles', 'informacoes_adicionais_fisco')) {
                $table->dropColumn('informacoes_adicionais_fisco');
            }

            if (Schema::hasColumn('fiscal_profiles', 'informacoes_adicionais_contribuinte')) {
                $table->dropColumn('informacoes_adicionais_contribuinte');
            }

            if (Schema::hasColumn('fiscal_profiles', 'informacoes_adicionais_compra')) {
                $table->dropColumn('informacoes_adicionais_compra');
            }

            if (Schema::hasColumn('fiscal_profiles', 'observacoes_contribuinte')) {
                $table->dropColumn('observacoes_contribuinte');
            }

            if (Schema::hasColumn('fiscal_profiles', 'observacoes_fisco')) {
                $table->dropColumn('observacoes_fisco');
            }
        });
    }

    public function down(): void
    {
        // Mantemos as colunas em inglês para evitar perda de dados em rollback.
    }
};
