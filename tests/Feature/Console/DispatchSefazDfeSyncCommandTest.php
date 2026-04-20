<?php

namespace Tests\Feature\Console;

use App\Jobs\SyncSefazDistributionCompanyJob;
use App\Models\Company;
use App\Models\CompanyPreference;
use App\Models\User;
use App\Services\Fiscal\Sefaz\CompanySefazCertificateService;
use App\Services\Fiscal\Sefaz\SefazDfeSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\TestCase;

class DispatchSefazDfeSyncCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_dispatches_jobs_only_for_eligible_companies(): void
    {
        Bus::fake();

        $eligible = $this->createCompany('11.111.111/0001-11');
        $recent = $this->createCompany('22.222.222/0001-22');
        $missingPassword = $this->createCompany('33.333.333/0001-33');
        $inactive = $this->createCompany('44.444.444/0001-44', isActive: false);

        CompanyPreference::set(CompanySefazCertificateService::PASSWORD_PREFERENCE_KEY, 'secret', $eligible->id);
        CompanyPreference::set(CompanySefazCertificateService::PASSWORD_PREFERENCE_KEY, 'secret', $recent->id);
        CompanyPreference::set(CompanySefazCertificateService::PASSWORD_PREFERENCE_KEY, 'secret', $inactive->id);

        CompanyPreference::set(SefazDfeSyncService::LAST_RUN_AT_KEY, now()->subHours(2)->toIso8601String(), $eligible->id);
        CompanyPreference::set(SefazDfeSyncService::LAST_RUN_AT_KEY, now()->subSeconds(20)->toIso8601String(), $recent->id);

        $this->artisan('sefaz:dfe-sync-dispatch')
            ->assertSuccessful();

        Bus::assertDispatched(SyncSefazDistributionCompanyJob::class, fn (SyncSefazDistributionCompanyJob $job) => true);
        Bus::assertDispatchedTimes(SyncSefazDistributionCompanyJob::class, 1);
    }

    public function test_command_waits_one_hour_and_ten_minutes_when_last_status_code_is_137_or_656(): void
    {
        Bus::fake();

        $company137 = $this->createCompany('55.555.555/0001-55');
        $company656 = $this->createCompany('66.666.666/0001-66');
        $released137 = $this->createCompany('77.777.777/0001-77');
        $normal = $this->createCompany('88.888.888/0001-88');

        foreach ([$company137, $company656, $released137, $normal] as $company) {
            CompanyPreference::set(CompanySefazCertificateService::PASSWORD_PREFERENCE_KEY, 'secret', $company->id);
        }

        CompanyPreference::set(SefazDfeSyncService::LAST_STATUS_CODE_KEY, '137', $company137->id);
        CompanyPreference::set(SefazDfeSyncService::LAST_RUN_AT_KEY, now()->subMinutes(60)->toIso8601String(), $company137->id);

        CompanyPreference::set(SefazDfeSyncService::LAST_STATUS_CODE_KEY, '656', $company656->id);
        CompanyPreference::set(SefazDfeSyncService::LAST_RUN_AT_KEY, now()->subMinutes(69)->toIso8601String(), $company656->id);

        CompanyPreference::set(SefazDfeSyncService::LAST_STATUS_CODE_KEY, '137', $released137->id);
        CompanyPreference::set(SefazDfeSyncService::LAST_RUN_AT_KEY, now()->subMinutes(71)->toIso8601String(), $released137->id);

        CompanyPreference::set(SefazDfeSyncService::LAST_STATUS_CODE_KEY, '138', $normal->id);
        CompanyPreference::set(SefazDfeSyncService::LAST_RUN_AT_KEY, now()->subMinutes(2)->toIso8601String(), $normal->id);

        $this->artisan('sefaz:dfe-sync-dispatch')
            ->assertSuccessful();

        Bus::assertDispatchedTimes(SyncSefazDistributionCompanyJob::class, 2);
    }

    private function createCompany(string $documentNumber, bool $isActive = true): Company
    {
        $user = User::factory()->create();

        return Company::query()->create([
            'name' => 'Empresa ' . Str::uuid(),
            'document_number' => preg_replace('/\D+/', '', $documentNumber),
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'email' => Str::uuid() . '@example.com',
            'certificate' => 'certificados/teste.pfx',
            'is_active' => $isActive,
            'created_by' => $user->id,
        ]);
    }
}
