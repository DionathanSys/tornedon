<?php

namespace Tests\Feature\Jobs;

use App\Enum\SefazDistributionDocument\ManifestationStatus;
use App\Enum\SefazDistributionDocument\Status;
use App\Jobs\ManifestSefazDistributionDocumentJob;
use App\Models\Company;
use App\Models\SefazDistributionDocument;
use App\Models\User;
use App\Services\Fiscal\Sefaz\SefazRecepcaoEventoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class ManifestSefazDistributionDocumentJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_retries_automatically_when_technical_failure_happens(): void
    {
        Bus::fake([ManifestSefazDistributionDocumentJob::class]);

        $document = $this->createDistributionDocument();

        $service = Mockery::mock(SefazRecepcaoEventoService::class);
        $service->shouldReceive('manifestScience')
            ->once()
            ->andThrow(new \RuntimeException('HTTP 500'));

        $this->app->instance(SefazRecepcaoEventoService::class, $service);

        $job = new ManifestSefazDistributionDocumentJob($document->id, 1);
        $job->handle(
            app(SefazRecepcaoEventoService::class),
            app(\App\Services\Fiscal\Sefaz\SefazDistributionDocumentService::class),
        );

        $document->refresh();

        $this->assertSame(ManifestationStatus::FAILED, $document->manifestation_status);
        $this->assertSame(Status::ERROR, $document->status);
        $this->assertSame('technical', data_get($document->distribution_payload, 'manifestation.failure_type'));
        $this->assertTrue((bool) data_get($document->distribution_payload, 'manifestation.retry_allowed'));

        Bus::assertDispatched(ManifestSefazDistributionDocumentJob::class);
    }

    public function test_job_does_not_retry_when_manifestation_is_functionally_rejected(): void
    {
        Bus::fake([ManifestSefazDistributionDocumentJob::class]);

        $document = $this->createDistributionDocument();

        $service = Mockery::mock(SefazRecepcaoEventoService::class);
        $service->shouldReceive('manifestScience')
            ->once()
            ->andReturn([
                'success' => false,
                'event_status_code' => '630',
                'event_status_message' => 'Rejeicao funcional',
            ]);

        $this->app->instance(SefazRecepcaoEventoService::class, $service);

        $job = new ManifestSefazDistributionDocumentJob($document->id, 1);
        $job->handle(
            app(SefazRecepcaoEventoService::class),
            app(\App\Services\Fiscal\Sefaz\SefazDistributionDocumentService::class),
        );

        $document->refresh();

        $this->assertSame(ManifestationStatus::REJECTED, $document->manifestation_status);
        $this->assertSame(Status::ERROR, $document->status);
        $this->assertSame('functional', data_get($document->distribution_payload, 'manifestation.failure_type'));
        $this->assertFalse((bool) data_get($document->distribution_payload, 'manifestation.retry_allowed'));

        Bus::assertNotDispatched(ManifestSefazDistributionDocumentJob::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    private function createDistributionDocument(): SefazDistributionDocument
    {
        $user = User::factory()->create();
        $company = Company::query()->create([
            'name' => 'Empresa Manifesto ' . Str::uuid(),
            'document_number' => '12345678000199',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'email' => Str::uuid() . '@example.com',
            'certificate' => 'certificates/1/teste.pfx',
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        return SefazDistributionDocument::query()->create([
            'company_id' => $company->id,
            'document_key' => '42260493978013000463550000000110111572905558',
            'nsu' => '000000000000001',
            'schema' => 'resNFe_v1.01.xsd',
            'document_type' => 'nfe',
            'status' => Status::DETECTED_SUMMARY,
            'manifestation_status' => ManifestationStatus::PENDING,
            'full_xml_available' => false,
            'last_seen_at' => now(),
        ]);
    }
}
