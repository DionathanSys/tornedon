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
        CompanyPreference::set(SefazDfeSyncService::LAST_RUN_AT_KEY, now()->subMinutes(20)->toIso8601String(), $recent->id);

        $this->artisan('sefaz:dfe-sync-dispatch')
            ->assertSuccessful();

        Bus::assertDispatched(SyncSefazDistributionCompanyJob::class, fn (SyncSefazDistributionCompanyJob $job) => true);
        Bus::assertDispatchedTimes(SyncSefazDistributionCompanyJob::class, 1);
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
