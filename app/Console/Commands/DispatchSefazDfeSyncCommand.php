<?php

namespace App\Console\Commands;

use App\Jobs\SyncSefazDistributionCompanyJob;
use App\Models\Company;
use App\Models\CompanyPreference;
use App\Services\Fiscal\Sefaz\CompanySefazCertificateService;
use App\Services\Fiscal\Sefaz\SefazDfeSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class DispatchSefazDfeSyncCommand extends Command
{
    protected $signature = 'sefaz:dfe-sync-dispatch';

    protected $description = 'Despacha a sincronização assíncrona de DF-e recebidos por empresa.';

    public function handle(): int
    {
        $companies = Company::query()
            ->where('is_active', true)
            ->whereNotNull('document_number')
            ->whereNotNull('certificate')
            ->get();

        $dispatched = 0;

        foreach ($companies as $company) {
            if (! $this->isEligible($company)) {
                continue;
            }

            SyncSefazDistributionCompanyJob::dispatch($company->id);
            $dispatched++;
        }

        $this->info("Jobs de sincronização DF-e despachados: {$dispatched}");

        return self::SUCCESS;
    }

    private function isEligible(Company $company): bool
    {
        $passwordPreference = CompanyPreference::get(CompanySefazCertificateService::PASSWORD_PREFERENCE_KEY, $company->id, '');
        $password = (string) (is_array($passwordPreference) ? ($passwordPreference['value'] ?? '') : ($passwordPreference ?? ''));
        if ($password === '') {
            return false;
        }

        $lastRunAt = CompanyPreference::get(SefazDfeSyncService::LAST_RUN_AT_KEY, $company->id);
        if (is_array($lastRunAt)) {
            $lastRunAt = $lastRunAt['value'] ?? null;
        }

        if (! is_string($lastRunAt) || trim($lastRunAt) === '') {
            return true;
        }

        try {
            return CarbonImmutable::parse($lastRunAt)->lte(now()->subMinutes(90));
        } catch (\Throwable) {
            return true;
        }
    }
}
