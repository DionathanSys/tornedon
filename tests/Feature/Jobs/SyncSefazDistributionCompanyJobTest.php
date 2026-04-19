<?php

namespace Tests\Feature\Jobs;

use App\Enum\SefazDistributionDocument\ManifestationStatus;
use App\Jobs\ManifestSefazDistributionDocumentJob;
use App\Jobs\SyncSefazDistributionCompanyJob;
use App\Models\Company;
use App\Models\CompanyPreference;
use App\Models\User;
use App\Services\Fiscal\Sefaz\DTO\DfeDistributionDocument;
use App\Services\Fiscal\Sefaz\DTO\DfeDistributionResult;
use App\Services\Fiscal\Sefaz\SefazDfeDistributionService;
use App\Services\Fiscal\Sefaz\SefazDfeSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class SyncSefazDistributionCompanyJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_persists_summary_document_and_dispatches_manifestation(): void
    {
        Bus::fake([ManifestSefazDistributionDocumentJob::class]);
        Storage::fake('local');

        $company = $this->createCompany();
        CompanyPreference::set(SefazDfeSyncService::LAST_NSU_KEY, '0', $company->id);

        $service = Mockery::mock(SefazDfeDistributionService::class);
        $service->shouldReceive('distribute')
            ->once()
            ->andReturn(new DfeDistributionResult(
                success: true,
                statusCode: '138',
                statusMessage: 'Documentos localizados',
                ultNsu: '000000000000050',
                maxNsu: '000000000000050',
                rawXml: '<retDistDFeInt/>',
                documents: [
                    new DfeDistributionDocument(
                        nsu: '000000000000050',
                        schema: 'resNFe_v1.01.xsd',
                        xml: '<resNFe xmlns="http://www.portalfiscal.inf.br/nfe"><chNFe>35260412345678000199550010000003211000000321</chNFe><CNPJ>12345678000199</CNPJ><xNome>Fornecedor Teste</xNome><dhEmi>2026-04-19T10:00:00-03:00</dhEmi><vNF>150.99</vNF><nNF>321</nNF><serie>1</serie></resNFe>',
                        accessKey: '35260412345678000199550010000003211000000321',
                    ),
                ],
            ));

        $this->app->instance(SefazDfeDistributionService::class, $service);

        $job = new SyncSefazDistributionCompanyJob($company->id);
        $job->handle(app(SefazDfeSyncService::class));

        $this->assertDatabaseHas('sefaz_distribution_documents', [
            'company_id' => $company->id,
            'document_key' => '35260412345678000199550010000003211000000321',
            'nsu' => '000000000000050',
            'manifestation_status' => ManifestationStatus::PENDING->value,
        ]);

        Bus::assertDispatched(ManifestSefazDistributionDocumentJob::class);
        $this->assertSame('000000000000050', CompanyPreference::get(SefazDfeSyncService::LAST_NSU_KEY, $company->id));
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    private function createCompany(): Company
    {
        $user = User::factory()->create();

        return Company::query()->create([
            'name' => 'Empresa Sync ' . Str::uuid(),
            'document_number' => '12345678000199',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'email' => Str::uuid() . '@example.com',
            'certificate' => 'certificados/teste.pfx',
            'is_active' => true,
            'created_by' => $user->id,
        ]);
    }
}
