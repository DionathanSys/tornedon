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
            if (! Schema::hasColumn('fiscal_profiles', 'icms_cst_default')) {
                $table->string('icms_cst_default', 3)->nullable()->after('cnae_principal');
            }

            if (! Schema::hasColumn('fiscal_profiles', 'icms_csosn_default')) {
                $table->string('icms_csosn_default', 4)->nullable()->after('icms_cst_default');
            }

            if (! Schema::hasColumn('fiscal_profiles', 'icms_aliquota_interna')) {
                $table->decimal('icms_aliquota_interna', 5, 2)->nullable()->after('icms_csosn_default');
            }

            if (! Schema::hasColumn('fiscal_profiles', 'icms_reducao_base')) {
                $table->decimal('icms_reducao_base', 5, 2)->nullable()->after('icms_aliquota_interna');
            }

            if (! Schema::hasColumn('fiscal_profiles', 'icms_modalidade_base_calculo')) {
                $table->string('icms_modalidade_base_calculo', 1)->nullable()->after('icms_reducao_base');
            }

            if (! Schema::hasColumn('fiscal_profiles', 'icms_st_aliquota')) {
                $table->decimal('icms_st_aliquota', 5, 2)->nullable()->after('icms_modalidade_base_calculo');
            }

            if (! Schema::hasColumn('fiscal_profiles', 'icms_st_mva')) {
                $table->decimal('icms_st_mva', 5, 2)->nullable()->after('icms_st_aliquota');
            }

            if (! Schema::hasColumn('fiscal_profiles', 'icms_st_reducao_base')) {
                $table->decimal('icms_st_reducao_base', 5, 2)->nullable()->after('icms_st_mva');
            }

            if (! Schema::hasColumn('fiscal_profiles', 'icms_aliquotas_interestaduais')) {
                $table->json('icms_aliquotas_interestaduais')->nullable()->after('icms_st_reducao_base');
            }

            if (! Schema::hasColumn('fiscal_profiles', 'pis_cst_default')) {
                $table->string('pis_cst_default', 3)->nullable()->after('icms_aliquotas_interestaduais');
            }

            if (! Schema::hasColumn('fiscal_profiles', 'pis_aliquota_default')) {
                $table->decimal('pis_aliquota_default', 5, 4)->nullable()->after('pis_cst_default');
            }

            if (! Schema::hasColumn('fiscal_profiles', 'cofins_cst_default')) {
                $table->string('cofins_cst_default', 3)->nullable()->after('pis_aliquota_default');
            }

            if (! Schema::hasColumn('fiscal_profiles', 'cofins_aliquota_default')) {
                $table->decimal('cofins_aliquota_default', 5, 4)->nullable()->after('cofins_cst_default');
            }

            if (! Schema::hasColumn('fiscal_profiles', 'ipi_cst_default')) {
                $table->string('ipi_cst_default', 3)->nullable()->after('cofins_aliquota_default');
            }

            if (! Schema::hasColumn('fiscal_profiles', 'ipi_aliquota_default')) {
                $table->decimal('ipi_aliquota_default', 5, 2)->nullable()->after('ipi_cst_default');
            }

            if (! Schema::hasColumn('fiscal_profiles', 'ipi_enquadramento')) {
                $table->string('ipi_enquadramento', 10)->nullable()->after('ipi_aliquota_default');
            }

            if (! Schema::hasColumn('fiscal_profiles', 'cfop_rules')) {
                $table->json('cfop_rules')->nullable()->after('ipi_enquadramento');
            }

            if (! Schema::hasColumn('fiscal_profiles', 'informacoes_complementares_padrao')) {
                $table->text('informacoes_complementares_padrao')->nullable()->after('cfop_rules');
            }

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

            if (! Schema::hasColumn('fiscal_profiles', 'ruleset_checksum')) {
                $table->string('ruleset_checksum', 64)->nullable()->after('informacoes_complementares_padrao');
            }
        });

        if (Schema::hasTable('fiscal_profile_versions')) {
            $latestByProfile = DB::table('fiscal_profile_versions')
                ->orderByDesc('version')
                ->orderByDesc('id')
                ->get()
                ->unique('fiscal_profile_id');

            foreach ($latestByProfile as $version) {
                DB::table('fiscal_profiles')
                    ->where('id', $version->fiscal_profile_id)
                    ->update([
                        'icms_cst_default' => $version->icms_cst_default,
                        'icms_csosn_default' => $version->icms_csosn_default,
                        'icms_aliquota_interna' => $version->icms_aliquota_interna,
                        'icms_reducao_base' => $version->icms_reducao_base,
                        'icms_modalidade_base_calculo' => $version->icms_modalidade_base_calculo,
                        'icms_st_aliquota' => $version->icms_st_aliquota,
                        'icms_st_mva' => $version->icms_st_mva,
                        'icms_st_reducao_base' => $version->icms_st_reducao_base,
                        'icms_aliquotas_interestaduais' => $version->icms_aliquotas_interestaduais,
                        'pis_cst_default' => $version->pis_cst_default,
                        'pis_aliquota_default' => $version->pis_aliquota_default,
                        'cofins_cst_default' => $version->cofins_cst_default,
                        'cofins_aliquota_default' => $version->cofins_aliquota_default,
                        'ipi_cst_default' => $version->ipi_cst_default,
                        'ipi_aliquota_default' => $version->ipi_aliquota_default,
                        'ipi_enquadramento' => $version->ipi_enquadramento,
                        'cfop_rules' => $version->cfop_rules,
                        'informacoes_complementares_padrao' => $version->informacoes_complementares_padrao,
                        'ruleset_checksum' => $version->ruleset_checksum,
                        'updated_at' => now(),
                    ]);
            }
        }

        if (Schema::hasTable('fiscal_rules') && Schema::hasColumn('fiscal_rules', 'fiscal_profile_version_id')) {
            Schema::table('fiscal_rules', function (Blueprint $table) {
                if (! Schema::hasColumn('fiscal_rules', 'fiscal_profile_id')) {
                    $table->foreignId('fiscal_profile_id')->nullable()->after('id')->constrained('fiscal_profiles')->nullOnDelete();
                }
            });

            if (Schema::hasTable('fiscal_profile_versions')) {
                $rules = DB::table('fiscal_rules')->select('id', 'fiscal_profile_version_id')->get();

                foreach ($rules as $rule) {
                    $profileId = DB::table('fiscal_profile_versions')
                        ->where('id', $rule->fiscal_profile_version_id)
                        ->value('fiscal_profile_id');

                    if ($profileId !== null) {
                        DB::table('fiscal_rules')
                            ->where('id', $rule->id)
                            ->update(['fiscal_profile_id' => $profileId]);
                    }
                }
            }

            Schema::table('fiscal_rules', function (Blueprint $table) {
                $table->dropConstrainedForeignId('fiscal_profile_version_id');
            });
        }

        if (Schema::hasTable('fiscal_documents') && Schema::hasColumn('fiscal_documents', 'fiscal_profile_version_id')) {
            Schema::table('fiscal_documents', function (Blueprint $table) {
                if (! Schema::hasColumn('fiscal_documents', 'fiscal_profile_id')) {
                    $table->foreignId('fiscal_profile_id')->nullable()->after('nfe_sequence_id')->constrained('fiscal_profiles')->nullOnDelete();
                }
            });

            if (Schema::hasTable('fiscal_profile_versions')) {
                $documents = DB::table('fiscal_documents')->select('id', 'fiscal_profile_version_id')->get();

                foreach ($documents as $document) {
                    $profileId = DB::table('fiscal_profile_versions')
                        ->where('id', $document->fiscal_profile_version_id)
                        ->value('fiscal_profile_id');

                    if ($profileId !== null) {
                        DB::table('fiscal_documents')
                            ->where('id', $document->id)
                            ->update(['fiscal_profile_id' => $profileId]);
                    }
                }
            }

            Schema::table('fiscal_documents', function (Blueprint $table) {
                $table->dropConstrainedForeignId('fiscal_profile_version_id');
            });
        }

        if (Schema::hasTable('fiscal_profile_versions')) {
            Schema::drop('fiscal_profile_versions');
        }
    }

    public function down(): void
    {
        // Migracao irreversivel de forma segura: os dados foram consolidados em fiscal_profiles.
    }
};
