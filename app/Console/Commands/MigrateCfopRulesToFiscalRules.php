<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\FiscalProfile;
use App\Models\FiscalRule;
use Illuminate\Support\Facades\DB;

class MigrateCfopRulesToFiscalRules extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fiscal:migrate-cfop-rules';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate JSON cfop_rules from fiscal_profiles to the new fiscal_rules table';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $profiles = FiscalProfile::all();

        $this->info("Found {$profiles->count()} fiscal profiles. Starting migration...");

        DB::transaction(function () use ($profiles) {
            foreach ($profiles as $profile) {
                $rules = $profile->cfop_rules ?? [];

                foreach ($rules as $operationNature => $ruleData) {
                    if (empty($ruleData['default_cfop'])) {
                        continue;
                    }

                    // 1. Regra padrão (sem NCM)
                    FiscalRule::updateOrCreate([
                        'company_id' => $profile->company_id,
                        'fiscal_profile_id' => $profile->id,
                        'operation_nature' => $operationNature,
                        'tax_regime' => $profile->tax_regime->value,
                        'ncm_prefix' => null,
                        'product_origin' => null,
                        'has_st' => null,
                        'recipient_type' => null,
                        'is_final_consumer' => null,
                        'is_interestadual' => false,
                    ], [
                        'cfop' => $ruleData['default_cfop'],
                        'priority' => 0,
                    ]);

                    // 2. Exceções por NCM
                    if (!empty($ruleData['exceptions'])) {
                        foreach ($ruleData['exceptions'] as $prefix => $cfop) {
                            FiscalRule::updateOrCreate([
                                'company_id' => $profile->company_id,
                                'fiscal_profile_id' => $profile->id,
                                'operation_nature' => $operationNature,
                                'tax_regime' => $profile->tax_regime->value,
                                'ncm_prefix' => $prefix,
                                'product_origin' => null,
                                'has_st' => null,
                                'recipient_type' => null,
                                'is_final_consumer' => null,
                                'is_interestadual' => false,
                            ], [
                                'cfop' => $cfop,
                                'priority' => 10,
                            ]);
                        }
                    }
                }
            }
        });

        $this->info('Migration completed successfully.');
    }
}


