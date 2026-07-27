<?php

namespace Tests\Feature\Services\FiscalDocument;

use App\Enum\FiscalDocument\BuyerPresenceIndicator;
use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\FreightModality;
use App\Enum\FiscalDocument\IssuePurpose;
use App\Enum\FiscalDocument\NfeStatus;
use App\Enum\FiscalDocument\OperationNature;
use App\Enum\FiscalDocument\OperationType;
use App\Enum\FiscalDocument\Status;
use App\Jobs\ProcessQueuedNfeEmissionJob;
use App\Models\Address;
use App\Models\Company;
use App\Models\CompanyPartner;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentItem;
use App\Models\FiscalDocumentTaxDetail;
use App\Models\FiscalProfile;
use App\Models\NfeSequence;
use App\Models\Partner;
use App\Models\Product;
use App\Models\ProductTax;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Fiscal\NfeConfigService;
use App\Services\FiscalDocument\NfeDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

class QueuedNfeEmissionFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_emitir_queues_document_after_preflight(): void
    {
        Bus::fake();

        [$user, $document] = $this->createReadyDocument();

        $config = Mockery::mock(NfeConfigService::class);
        $config->shouldReceive('resolveAmbiente')->andReturn(2);
        $config->shouldReceive('resolveSerie')->andReturn('1');
        $this->app->instance(NfeConfigService::class, $config);

        $service = app(NfeDocumentService::class);
        $result = $service->emitir($document, $user->id);

        $this->assertTrue($result);

        $document->refresh();

        $this->assertSame(NfeStatus::QUEUED, $document->nfe_status);
        $this->assertNotNull($document->emission_requested_at);
        $this->assertSame("nfe:{$document->company_id}:1:2", $document->emission_group_key);

        Bus::assertDispatched(ProcessQueuedNfeEmissionJob::class);
    }

    public function test_queue_job_reuses_number_when_first_document_fails_before_api_acceptance(): void
    {
        Bus::fake();

        [$user, $firstDocument] = $this->createReadyDocument();
        [, $secondDocument] = $this->createReadyDocument(company: $firstDocument->company, customer: $firstDocument->customer, user: $user);

        $firstDocument->update([
            'nfe_status' => NfeStatus::QUEUED->value,
            'emission_group_key' => "nfe:{$firstDocument->company_id}:1:2",
            'emission_requested_at' => now()->subMinute(),
        ]);
        $firstDocument->taxDetail()->update(['freight_data' => []]);

        $secondDocument->update([
            'nfe_status' => NfeStatus::QUEUED->value,
            'emission_group_key' => "nfe:{$secondDocument->company_id}:1:2",
            'emission_requested_at' => now(),
        ]);

        $config = Mockery::mock(NfeConfigService::class);
        $config->shouldReceive('resolveAmbiente')->andReturn(2);
        $config->shouldReceive('resolveSerie')->andReturn('1');
        $config->shouldReceive('resolveToken')->andReturn('fake-token');
        $config->shouldReceive('buildSdkParams')->andReturn([
            'token' => 'fake-token',
            'ambiente' => 2,
            'options' => [],
        ]);
        $this->app->instance(NfeConfigService::class, $config);

        $audit = Mockery::mock(AuditRecorder::class);
        $audit->shouldReceive('snapshot')->andReturn([]);
        $audit->shouldReceive('recordModelEvent')->once();
        $this->app->instance(AuditRecorder::class, $audit);

        $sdk = Mockery::mock('overload:CloudDfe\SdkPHP\Nfe');
        $sdk->shouldReceive('cria')
            ->once()
            ->andReturn((object) [
                'sucesso' => true,
                'codigo' => 5023,
                'chave' => '35260412345678000199550010000000011000000011',
            ]);

        $job = new ProcessQueuedNfeEmissionJob("nfe:{$firstDocument->company_id}:1:2");
        $job->handle();

        $firstDocument->refresh();
        $secondDocument->refresh();

        $secondState = json_encode([
            'status' => $secondDocument->status,
            'nfe_status' => $secondDocument->nfe_status?->value,
            'document_number' => $secondDocument->document_number,
            'document_series' => $secondDocument->document_series,
            'document_key' => $secondDocument->document_key,
            'errors_messages' => $secondDocument->errors_messages,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertNull($firstDocument->document_number);
        $this->assertNull($firstDocument->document_series);
        $this->assertSame(NfeStatus::PENDING, $firstDocument->nfe_status);
        $this->assertNotEmpty($firstDocument->errors_messages);

        $this->assertSame('1', $secondDocument->document_number, $secondState ?: '');
        $this->assertSame('1', $secondDocument->document_series, $secondState ?: '');
        $this->assertSame(NfeStatus::IN_PROCESSING, $secondDocument->nfe_status, $secondState ?: '');
        $this->assertSame('35260412345678000199550010000000011000000011', $secondDocument->document_key, $secondState ?: '');
        $this->assertNotNull($secondDocument->nfe_sequence_id);

        $sequence = NfeSequence::query()->first();

        $this->assertNotNull($sequence);
        $this->assertSame(1, $sequence->last_number);
    }

    private function createReadyDocument(?Company $company = null, ?Partner $customer = null, ?User $user = null): array
    {
        $user ??= User::factory()->create();

        $company ??= Company::query()->create([
            'name' => 'Empresa Emissão',
            'document_number' => '12345678000199',
            'address' => [
                'city' => 'Sao Paulo',
                'state' => 'SP',
            ],
            'created_by' => $user->id,
        ]);

        FiscalProfile::query()->firstOrCreate(
            ['company_id' => $company->id],
            [
                'tax_regime' => 'simples_nacional',
                'cfop_rules' => [
                    OperationNature::VENDA_DENTRO_ESTADO->value => [
                        'default_cfop' => '5102',
                    ],
                ],
                'is_active' => true,
                'created_by' => $user->id,
            ],
        );

        $customer ??= Partner::query()->create([
            'name' => 'Cliente Emissão',
            'document_type' => 'CNPJ',
            'document_number' => '22345678000188',
            'state_tax_id' => '110042490114',
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
                'city' => 'Sao Paulo',
                'state' => 'SP',
                'postal_code' => '01000-000',
                'city_code' => '3550308',
                'created_by' => $user->id,
            ],
        );

        $product = Product::query()->create([
            'company_id' => $company->id,
            'product_code' => 'PRD-'.str_pad((string) $company->id, 4, '0', STR_PAD_LEFT).'-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            'name' => 'Produto de Teste',
            'unit' => 'UN',
            'sale_price_value' => 100,
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        ProductTax::query()->create([
            'product_id' => $product->id,
            'product_origin' => '0',
            'ncm_code' => '84733049',
            'created_by' => $user->id,
        ]);

        $document = FiscalDocument::query()->create([
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'status' => Status::PENDING->value,
            'document_type' => DocumentModel::NFE->value,
            'issued_at' => now()->subDay()->toDateString(),
            'movement_at' => now()->subDay()->toDateString(),
            'operation_nature' => OperationNature::VENDA_DENTRO_ESTADO->value,
            'operation_type' => OperationType::SAIDA->value,
            'issue_purpose' => IssuePurpose::NORMAL->value,
            'is_final_consumer' => false,
            'buyer_presence_indicator' => BuyerPresenceIndicator::OUTROS->value,
            'freight_data' => [
                'modalidade_frete' => FreightModality::SEM_FRETE->value,
            ],
            'nfe_status' => NfeStatus::PENDING->value,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        FiscalDocumentTaxDetail::query()->create([
            'company_id' => $document->company_id,
            'fiscal_document_id' => $document->id,
            'freight_data' => [
                'modalidade_frete' => FreightModality::SEM_FRETE->value,
            ],
        ]);

        FiscalDocumentItem::query()->create([
            'fiscal_document_id' => $document->id,
            'product_id' => $product->id,
            'product_code' => $product->product_code,
            'description' => $product->name,
            'item_number' => 1,
            'product_origin' => '0',
            'ncm_code' => '84733049',
            'cfop_code' => '5102',
            'quantity' => 1,
            'unit_of_measure' => 'UN',
            'unit_price' => 100,
            'total_price' => 100,
            'included_in_total' => true,
            'tax_data' => [
                'imposto' => [
                    'icms' => ['situacao_tributaria' => '102'],
                    'pis' => ['situacao_tributaria' => '01'],
                    'cofins' => ['situacao_tributaria' => '01'],
                ],
            ],
            'created_by' => $user->id,
        ]);

        return [$user, $document];
    }
}
