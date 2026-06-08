<?php

namespace Tests\Feature\Services\FiscalDocument;

use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\NfeStatus;
use App\Enum\FiscalDocument\NfseModel;
use App\Enum\FiscalDocument\Status;
use App\Jobs\ProcessQueuedNfseEmissionJob;
use App\Models\Address;
use App\Models\Company;
use App\Models\CompanyPartner;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentItem;
use App\Models\FiscalProfile;
use App\Models\NfseSequence;
use App\Models\Partner;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Fiscal\NfseConfigService;
use App\Services\FiscalDocument\NfseDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

class QueuedNfseEmissionFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_emitir_queues_nfse_after_preflight(): void
    {
        Bus::fake();

        [$user, $document] = $this->createReadyDocument();

        $config = Mockery::mock(NfseConfigService::class);
        $config->shouldReceive('resolveAmbiente')->andReturn(2);
        $config->shouldReceive('resolveSerie')->andReturn('1');
        $this->app->instance(NfseConfigService::class, $config);

        $service = app(NfseDocumentService::class);
        $result = $service->emitir($document, $user->id);

        $this->assertTrue($result);

        $document->refresh();

        $this->assertSame(NfeStatus::QUEUED, $document->nfse_status);
        $this->assertNotNull($document->emission_requested_at);
        $this->assertSame("nfse:{$document->company_id}:1:2", $document->emission_group_key);

        Bus::assertDispatched(ProcessQueuedNfseEmissionJob::class);
    }

    public function test_queue_job_reuses_rps_number_when_first_document_fails_before_api_acceptance(): void
    {
        Bus::fake();

        [$user, $firstDocument] = $this->createReadyDocument();
        [, $secondDocument] = $this->createReadyDocument(company: $firstDocument->company, customer: $firstDocument->customer, user: $user);

        $firstDocument->update([
            'nfse_status' => NfeStatus::QUEUED->value,
            'emission_group_key' => "nfse:{$firstDocument->company_id}:1:2",
            'emission_requested_at' => now()->subMinute(),
        ]);
        $firstDocument->items()->first()?->update([
            'total_price' => 0,
        ]);

        $secondDocument->update([
            'nfse_status' => NfeStatus::QUEUED->value,
            'emission_group_key' => "nfse:{$secondDocument->company_id}:1:2",
            'emission_requested_at' => now(),
        ]);

        $config = Mockery::mock(NfseConfigService::class);
        $config->shouldReceive('resolveAmbiente')->andReturn(2);
        $config->shouldReceive('resolveSerie')->andReturn('1');
        $config->shouldReceive('buildSdkParams')->andReturn([
            'token' => 'fake-token',
            'ambiente' => 2,
            'options' => [],
        ]);
        $this->app->instance(NfseConfigService::class, $config);

        $audit = Mockery::mock(AuditRecorder::class);
        $audit->shouldReceive('snapshot')->andReturn([]);
        $audit->shouldReceive('recordModelEvent')->once();
        $this->app->instance(AuditRecorder::class, $audit);

        $sdk = Mockery::mock('overload:CloudDfe\SdkPHP\Nfse');
        $sdk->shouldReceive('cria')
            ->once()
            ->andReturn((object) [
                'sucesso' => true,
                'codigo' => 5023,
                'chave' => 'NFSE-KEY-0001',
            ]);

        $job = new ProcessQueuedNfseEmissionJob("nfse:{$firstDocument->company_id}:1:2");
        $job->handle();

        $firstDocument->refresh();
        $secondDocument->refresh();

        $this->assertNull($firstDocument->rps_number);
        $this->assertNull($firstDocument->rps_series);
        $this->assertSame(NfeStatus::PENDING, $firstDocument->nfse_status);
        $this->assertNotEmpty($firstDocument->errors_messages);

        $this->assertSame('1', $secondDocument->rps_number);
        $this->assertSame('1', $secondDocument->rps_series);
        $this->assertSame(NfeStatus::IN_PROCESSING, $secondDocument->nfse_status);
        $this->assertSame('NFSE-KEY-0001', $secondDocument->document_key);
        $this->assertNotNull($secondDocument->nfse_sequence_id);

        $sequence = NfseSequence::query()->first();

        $this->assertNotNull($sequence);
        $this->assertSame(1, $sequence->last_number);
    }

    public function test_queue_job_releases_rps_number_after_immediate_api_rejection(): void
    {
        Bus::fake();

        [$user, $document] = $this->createReadyDocument();

        $document->update([
            'nfse_status' => NfeStatus::QUEUED->value,
            'emission_group_key' => "nfse:{$document->company_id}:1:2",
            'emission_requested_at' => now(),
        ]);

        $config = Mockery::mock(NfseConfigService::class);
        $config->shouldReceive('resolveAmbiente')->andReturn(2);
        $config->shouldReceive('resolveSerie')->andReturn('1');
        $config->shouldReceive('buildSdkParams')->andReturn([
            'token' => 'fake-token',
            'ambiente' => 2,
            'options' => [],
        ]);
        $this->app->instance(NfseConfigService::class, $config);

        $sdk = Mockery::mock('overload:CloudDfe\SdkPHP\Nfse');
        $sdk->shouldReceive('cria')
            ->once()
            ->andReturn((object) [
                'sucesso' => false,
                'codigo' => 5001,
                'mensagem' => 'Erro de validação',
                'erros' => [],
            ]);

        $job = new ProcessQueuedNfseEmissionJob("nfse:{$document->company_id}:1:2");
        $job->handle();

        $document->refresh();

        $this->assertSame(NfeStatus::PENDING, $document->nfse_status);
        $this->assertNull($document->rps_number);
        $this->assertNull($document->rps_series);
        $this->assertNull($document->nfse_sequence_id);
        $this->assertSame(0, (int) NfseSequence::query()->where('company_id', $document->company_id)->where('serie', '1')->value('last_number'));
    }

    public function test_queue_job_marks_document_for_reconciliation_on_ambiguous_api_failure(): void
    {
        Bus::fake();

        [$user, $document] = $this->createReadyDocument();

        $document->update([
            'nfse_status' => NfeStatus::QUEUED->value,
            'emission_group_key' => "nfse:{$document->company_id}:1:2",
            'emission_requested_at' => now(),
        ]);

        $config = Mockery::mock(NfseConfigService::class);
        $config->shouldReceive('resolveAmbiente')->andReturn(2);
        $config->shouldReceive('resolveSerie')->andReturn('1');
        $config->shouldReceive('buildSdkParams')->andReturn([
            'token' => 'fake-token',
            'ambiente' => 2,
            'options' => [],
        ]);
        $this->app->instance(NfseConfigService::class, $config);

        $sdk = Mockery::mock('overload:CloudDfe\SdkPHP\Nfse');
        $sdk->shouldReceive('cria')
            ->once()
            ->andThrow(new \RuntimeException('timeout'));

        $job = new ProcessQueuedNfseEmissionJob("nfse:{$document->company_id}:1:2");
        $job->handle();

        $document->refresh();

        $this->assertSame(NfeStatus::RPS_RECONCILIATION_PENDING, $document->nfse_status);
        $this->assertSame('1', $document->rps_number);
        $this->assertSame('1', $document->rps_series);
        $this->assertNotEmpty($document->errors_messages);
    }

    public function test_send_nfse_blocks_retry_when_document_rps_is_not_current_last_number(): void
    {
        [$user, $document] = $this->createReadyDocument();

        $sequence = NfseSequence::query()->create([
            'company_id' => $document->company_id,
            'serie' => '1',
            'last_number' => 2,
        ]);

        $document->update([
            'rps_number' => '1',
            'rps_series' => '1',
            'nfse_sequence_id' => $sequence->id,
            'nfse_status' => NfeStatus::PENDING->value,
        ]);

        $config = Mockery::mock(NfseConfigService::class);
        $config->shouldReceive('resolveSerie')->andReturn('1');
        $this->app->instance(NfseConfigService::class, $config);

        $action = new \App\Services\FiscalDocument\Actions\SendNfseAction($user->id);

        $result = $action->execute($document->fresh(), '1', 'municipal_nfse');

        $this->assertFalse($result);

        $document->refresh();

        $this->assertSame(NfeStatus::RPS_RECONCILIATION_PENDING, $document->nfse_status);
        $this->assertSame('1', $document->rps_number);
        $this->assertNotEmpty($document->errors_messages);
    }

    public function test_queue_job_synchronizes_legacy_sequence_when_reserved_rps_is_current_tail(): void
    {
        Bus::fake();

        [$user, $document] = $this->createReadyDocument();

        $sequence = NfseSequence::query()->create([
            'company_id' => $document->company_id,
            'serie' => '1',
            'last_number' => 0,
        ]);

        $document->update([
            'rps_number' => '1',
            'rps_series' => '1',
            'nfse_sequence_id' => $sequence->id,
            'nfse_status' => NfeStatus::PENDING->value,
            'emission_group_key' => "nfse:{$document->company_id}:1:2",
            'emission_requested_at' => now(),
        ]);

        $config = Mockery::mock(NfseConfigService::class);
        $config->shouldReceive('resolveAmbiente')->andReturn(2);
        $config->shouldReceive('resolveSerie')->andReturn('1');
        $config->shouldReceive('buildSdkParams')->andReturn([
            'token' => 'fake-token',
            'ambiente' => 2,
            'options' => [],
        ]);
        $this->app->instance(NfseConfigService::class, $config);

        $audit = Mockery::mock(AuditRecorder::class);
        $audit->shouldReceive('snapshot')->andReturn([]);
        $audit->shouldReceive('recordModelEvent')->once();
        $this->app->instance(AuditRecorder::class, $audit);

        $sdk = Mockery::mock('overload:CloudDfe\SdkPHP\Nfse');
        $sdk->shouldReceive('cria')
            ->once()
            ->andReturn((object) [
                'sucesso' => true,
                'codigo' => 5023,
                'chave' => 'NFSE-LEGACY-KEY-0001',
            ]);

        $document->update([
            'nfse_status' => NfeStatus::QUEUED->value,
        ]);

        $job = new ProcessQueuedNfseEmissionJob("nfse:{$document->company_id}:1:2");
        $job->handle();

        $document->refresh();
        $sequence->refresh();

        $this->assertSame(NfeStatus::IN_PROCESSING, $document->nfse_status);
        $this->assertSame('1', $document->rps_number);
        $this->assertSame(1, $sequence->last_number);
    }

    private function createReadyDocument(?Company $company = null, ?Partner $customer = null, ?User $user = null): array
    {
        $user ??= User::factory()->create();

        $company ??= Company::query()->create([
            'name' => 'Empresa NFSE',
            'document_number' => '12345678000199',
            'address' => [
                'city' => 'Chapeco',
                'state' => 'SC',
                'city_code' => '4204202',
            ],
            'created_by' => $user->id,
        ]);

        FiscalProfile::query()->firstOrCreate(
            ['company_id' => $company->id],
            [
                'tax_regime' => 'simples_nacional',
                'default_service_code' => '01.01',
                'default_municipal_tax_code' => '01.01',
                'default_nbs_code' => '123456789',
                'default_service_city_code' => '4204202',
                'iss_rate_default' => 5,
                'is_active' => true,
                'created_by' => $user->id,
            ],
        );

        $customer ??= Partner::query()->create([
            'name' => 'Cliente NFSE',
            'document_type' => 'CNPJ',
            'document_number' => '22345678000188',
            'created_by' => $user->id,
        ]);

        $companyPartner = CompanyPartner::query()->firstOrCreate(
            [
                'company_id' => $company->id,
                'partner_id' => $customer->id,
            ],
            [
                'type' => ['customer'],
                'is_active' => true,
            ],
        );

        Address::query()->firstOrCreate(
            ['company_partner_id' => $companyPartner->id],
            [
                'street' => 'Rua Teste',
                'number' => '100',
                'neighborhood' => 'Centro',
                'city' => 'Chapeco',
                'state' => 'SC',
                'postal_code' => '89800-000',
                'city_code' => '4204202',
                'created_by' => $user->id,
            ],
        );

        $document = FiscalDocument::query()->create([
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'status' => Status::PENDING->value,
            'document_type' => DocumentModel::NFSE->value,
            'nfse_model' => NfseModel::MUNICIPAL->value,
            'issued_at' => now()->subDay()->toDateString(),
            'movement_at' => now()->subDay()->toDateString(),
            'nfse_status' => NfeStatus::PENDING->value,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        FiscalDocumentItem::query()->create([
            'fiscal_document_id' => $document->id,
            'item_number' => 1,
            'description' => 'Servico de teste',
            'quantity' => 1,
            'unit_of_measure' => 'UN',
            'unit_price' => 100,
            'total_price' => 100,
            'municipal_tax_code' => '01.01',
            'nbs_code' => '123456789',
            'iss_exigibility' => '1',
            'iss_rate' => 5,
            'included_in_total' => true,
            'created_by' => $user->id,
        ]);

        return [$user, $document];
    }
}
