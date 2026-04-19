<?php

namespace App\Jobs;

use App\Models\Company;
use App\Services\Fiscal\Sefaz\SefazDfeSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncSefazDistributionCompanyJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $uniqueFor = 5400;

    public function __construct(
        private readonly int $companyId,
    ) {
    }

    public function uniqueId(): string
    {
        return 'sefaz-dfe-sync-company-' . $this->companyId;
    }

    public function handle(SefazDfeSyncService $syncService): void
    {
        $company = Company::query()->find($this->companyId);
        if (! $company) {
            return;
        }

        try {
            $syncService->syncCompany($company);
        } catch (\Throwable $exception) {
            $syncService->markFailure($company, $exception);
        }
    }
}
