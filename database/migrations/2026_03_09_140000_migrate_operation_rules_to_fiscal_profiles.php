<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('operation_rules') || ! Schema::hasTable('fiscal_profiles')) {
            return;
        }

        $rules = DB::table('operation_rules')
            ->where('is_active', true)
            ->orderBy('company_id')
            ->get();

        $profilesByCompany = DB::table('fiscal_profiles')
            ->get()
            ->keyBy('company_id');

        $groupedRules = $rules->groupBy('company_id');

        foreach ($groupedRules as $companyId => $companyRules) {
            $profile = $profilesByCompany->get($companyId);

            if (! $profile) {
                continue;
            }

            $currentRules = json_decode($profile->cfop_rules ?? '[]', true);
            if (! is_array($currentRules)) {
                $currentRules = [];
            }

            foreach ($companyRules as $rule) {
                $exceptions = json_decode($rule->cfop_exceptions ?? '[]', true);

                if (! is_array($exceptions)) {
                    $exceptions = [];
                }

                $normalizedExceptions = [];
                foreach ($exceptions as $exception) {
                    $prefix = $exception['ncm_prefix'] ?? null;
                    $cfop = $exception['cfop'] ?? null;

                    if (! is_string($prefix) || ! is_string($cfop) || $prefix === '' || $cfop === '') {
                        continue;
                    }

                    $normalizedExceptions[$prefix] = $cfop;
                }

                $currentRules[$rule->operation_nature] = [
                    'default_cfop' => $rule->default_cfop,
                    'exceptions' => $normalizedExceptions,
                ];
            }

            DB::table('fiscal_profiles')
                ->where('id', $profile->id)
                ->update([
                    'cfop_rules' => json_encode($currentRules, JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                ]);
        }

        Schema::dropIfExists('operation_rules');
    }

    public function down(): void
    {
        // Sem rollback: dados foram consolidados em fiscal_profiles.cfop_rules.
    }
};
