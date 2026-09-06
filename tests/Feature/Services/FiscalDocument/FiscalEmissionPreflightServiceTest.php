<?php

namespace Tests\Feature\Services\FiscalDocument;

use App\Enum\FiscalDocument\BuyerPresenceIndicator;
use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\FreightModality;
use App\Enum\FiscalDocument\IssuePurpose;
use App\Enum\FiscalDocument\NfeStatus;
use App\Enum\FiscalDocument\NfseModel;
use App\Enum\FiscalDocument\OperationNature;
use App\Enum\FiscalDocument\OperationType;
use App\Enum\FiscalDocument\Status;
use App\Models\Address;
use App\Models\Company;
use App\Models\CompanyPartner;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentItem;
use App\Models\FiscalDocumentItemOrigin;
use App\Models\FiscalDocumentTaxDetail;
use App\Models\FiscalProfile;
use App\Models\Partner;
use App\Models\Product;
use App\Models\User;
use App\Services\Fiscal\NfeConfigService;
use App\Services\Fiscal\NfseConfigService;
use App\Services\FiscalDocument\FiscalEmissionPreflightService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class FiscalEmissionPreflightServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_nfe_sale_preflight_returns_sale_scenario(): void
    {
        [, $document] = $this->createReadyNfeDocument();

        $config = Mockery::mock(NfeConfigService::class);
        $config->shouldReceive('resolveAmbiente')->andReturn(2);
        $config->shouldReceive('resolveSerie')->andReturn('1');
        $this->app->instance(NfeConfigService::class, $config);

        $service = app(FiscalEmissionPreflightService::class);
        $result = $service->validateForQueue($document);

        $this->assertNotNull($result, $service->getMessage());
        $this->assertSame('sale', $result->scenarioCode);
        $this->assertSame('nfe', $result->channelCode);
        $this->assertSame('nfe:default', $result->payloadBuilderKey);
        $this->assertSame("nfe:{$document->company_id}:1:2", $result->queueGroupKey);
        $this->assertSame(1, $result->candidateNumber);
    }

    public function test_nfe_purchase_return_requires_reference(): void
    {
        [, $document] = $this->createReadyNfeDocument(
            operationNature: OperationNature::DEVOLUCAO_COMPRA,
            issuePurpose: IssuePurpose::DEVOLUCAO,
        );

        $config = Mockery::mock(NfeConfigService::class);
        $config->shouldReceive('resolveAmbiente')->andReturn(2);
        $config->shouldReceive('resolveSerie')->andReturn('1');
        $this->app->instance(NfeConfigService::class, $config);

        $service = app(FiscalEmissionPreflightService::class);
        $result = $service->validateForQueue($document);

        $this->assertNull($result);
        $this->assertArrayHasKey('tax_data.purchase_return_origin.document_key', $service->getErrors());
    }

    public function test_nfe_purchase_return_uses_item_origin_link_as_reference_fallback(): void
    {
        [, $document] = $this->createReadyNfeDocument(
            operationNature: OperationNature::DEVOLUCAO_COMPRA,
            issuePurpose: IssuePurpose::DEVOLUCAO,
        );

        $originDocument = FiscalDocument::query()->create([
            'customer_id' => $document->customer_id,
            'company_id' => $document->company_id,
            'status' => Status::CONFIRMED->value,
            'document_type' => DocumentModel::NFE->value,
            'issued_at' => now()->subDays(2)->toDateString(),
            'movement_at' => now()->subDays(2)->toDateString(),
            'document_number' => '57018',
            'document_series' => '1',
            'document_key' => '42260890136409002095550010000570181512864919',
            'operation_nature' => OperationNature::VENDA_DENTRO_ESTADO->value,
            'operation_type' => OperationType::ENTRADA->value,
            'issue_purpose' => IssuePurpose::NORMAL->value,
            'is_final_consumer' => false,
            'buyer_presence_indicator' => BuyerPresenceIndicator::OUTROS->value,
            'freight_data' => ['modalidade_frete' => FreightModality::SEM_FRETE->value],
            'created_by' => $document->created_by,
            'updated_by' => $document->updated_by,
        ]);

        $returnItem = $document->items()->firstOrFail();
        $originItem = $returnItem->replicate(['id']);
        $originItem->fiscal_document_id = $originDocument->id;
        $originItem->save();

        FiscalDocumentItemOrigin::query()->create([
            'origin_fiscal_document_id' => $originDocument->id,
            'origin_fiscal_document_item_id' => $originItem->id,
            'return_fiscal_document_id' => $document->id,
            'return_fiscal_document_item_id' => $returnItem->id,
            'linked_quantity' => 1,
            'linked_value' => 100,
            'origin_document_key' => '42260890136409002095550010000570181512864919',
        ]);

        $config = Mockery::mock(NfeConfigService::class);
        $config->shouldReceive('resolveAmbiente')->andReturn(2);
        $config->shouldReceive('resolveSerie')->andReturn('1');
        $this->app->instance(NfeConfigService::class, $config);

        $service = app(FiscalEmissionPreflightService::class);
        $result = $service->validateForQueue($document->fresh('items'));

        $this->assertNotNull($result, $service->getMessage());
        $this->assertSame('42260890136409002095550010000570181512864919', $result->scenarioContext->referenceDocumentKey);
    }

    public function test_nfse_municipal_preflight_requires_municipal_tax_code_and_nbs(): void
    {
        [, $document] = $this->createReadyNfseDocument(withServiceDefaults: false, withItemTaxCodes: false);

        $config = Mockery::mock(NfseConfigService::class);
        $config->shouldReceive('resolveAmbiente')->andReturn(2);
        $config->shouldReceive('resolveSerie')->andReturn('1');
        $this->app->instance(NfseConfigService::class, $config);

        $service = app(FiscalEmissionPreflightService::class);
        $result = $service->validateForQueue($document);

        $this->assertNull($result);
        $this->assertArrayHasKey('items.0.municipal_tax_code', $service->getErrors());
        $this->assertArrayHasKey('items.0.nbs_code', $service->getErrors());
    }

    public function test_nfse_national_preflight_returns_builder_and_group(): void
    {
        [, $document] = $this->createReadyNfseDocument(model: NfseModel::NACIONAL);

        $config = Mockery::mock(NfseConfigService::class);
        $config->shouldReceive('resolveAmbiente')->andReturn(2);
        $config->shouldReceive('resolveSerie')->andReturn('1');
        $this->app->instance(NfseConfigService::class, $config);

        $service = app(FiscalEmissionPreflightService::class);
        $result = $service->validateForQueue($document);

        $this->assertNotNull($result, $service->getMessage());
        $this->assertSame('national_nfse', $result->scenarioCode);
        $this->assertSame('nfse:nacional', $result->channelCode);
        $this->assertSame('nacional:default', $result->payloadBuilderKey);
        $this->assertSame("nfse:{$document->company_id}:1:2", $result->queueGroupKey);
        $this->assertSame(1, $result->candidateNumber);
    }

    public function test_nfse_national_preflight_requires_regime_apuracao_for_simples_nacional_me_epp(): void
    {
        [, $document] = $this->createReadyNfseDocument(
            model: NfseModel::NACIONAL,
            fiscalProfileOverrides: [
                'tax_regime' => 'simples_nacional',
                'nfse_special_tax_regime' => '6',
                'nfse_nacional_regime_apuracao' => null,
            ],
        );

        $config = Mockery::mock(NfseConfigService::class);
        $config->shouldReceive('resolveAmbiente')->andReturn(2);
        $config->shouldReceive('resolveSerie')->andReturn('1');
        $this->app->instance(NfseConfigService::class, $config);

        $service = app(FiscalEmissionPreflightService::class);
        $result = $service->validateForQueue($document);

        $this->assertNull($result);
        $this->assertArrayHasKey('fiscal_profile.nfse_nacional_regime_apuracao', $service->getErrors());
    }

    private function createReadyNfeDocument(
        ?Company $company = null,
        ?Partner $customer = null,
        ?User $user = null,
        OperationNature $operationNature = OperationNature::VENDA_DENTRO_ESTADO,
        IssuePurpose $issuePurpose = IssuePurpose::NORMAL,
    ): array {
        $user ??= User::factory()->create();

        $company ??= Company::query()->create([
            'name' => 'Empresa NFE',
            'document_number' => '12345678000199',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP', 'city_code' => '3550308'],
            'created_by' => $user->id,
        ]);

        FiscalProfile::query()->updateOrCreate(
            ['company_id' => $company->id],
            [
                'tax_regime' => 'simples_nacional',
                'cfop_rules' => [
                    OperationNature::VENDA_DENTRO_ESTADO->value => ['default_cfop' => '5102'],
                    OperationNature::DEVOLUCAO_COMPRA->value => ['default_cfop' => '5202'],
                ],
                'is_active' => true,
                'created_by' => $user->id,
            ],
        );

        $customer ??= Partner::query()->create([
            'name' => 'Cliente NFE',
            'document_type' => 'CNPJ',
            'document_number' => '22345678000188',
            'state_tax_id' => '110042490114',
            'created_by' => $user->id,
        ]);

        $this->attachAddress($company, $customer, $user, 'Sao Paulo', 'SP', '3550308');

        $product = Product::query()->create([
            'company_id' => $company->id,
            'product_code' => 'PRD-NFE-001',
            'name' => 'Produto NFE',
            'unit' => 'UN',
            'sale_price_value' => 100,
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        $document = FiscalDocument::query()->create([
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'status' => Status::PENDING->value,
            'document_type' => DocumentModel::NFE->value,
            'issued_at' => now()->subDay()->toDateString(),
            'movement_at' => now()->subDay()->toDateString(),
            'operation_nature' => $operationNature->value,
            'operation_type' => OperationType::SAIDA->value,
            'issue_purpose' => $issuePurpose->value,
            'is_final_consumer' => false,
            'buyer_presence_indicator' => BuyerPresenceIndicator::OUTROS->value,
            'freight_data' => ['modalidade_frete' => FreightModality::SEM_FRETE->value],
            'nfe_status' => NfeStatus::PENDING->value,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        FiscalDocumentItem::query()->create([
            'fiscal_document_id' => $document->id,
            'product_id' => $product->id,
            'product_code' => $product->product_code,
            'description' => $product->name,
            'item_number' => 1,
            'product_origin' => '0',
            'ncm_code' => '84733049',
            'cfop_code' => $operationNature === OperationNature::DEVOLUCAO_COMPRA ? '5202' : '5102',
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

        FiscalDocumentTaxDetail::query()->create([
            'company_id' => $company->id,
            'fiscal_document_id' => $document->id,
            'freight_data' => ['modalidade_frete' => FreightModality::SEM_FRETE->value],
            'fiscal_metadata' => ['intermediario' => ['indicador' => null]],
        ]);

        return [$user, $document];
    }

    private function createReadyNfseDocument(
        ?Company $company = null,
        ?Partner $customer = null,
        ?User $user = null,
        NfseModel $model = NfseModel::MUNICIPAL,
        bool $withServiceDefaults = true,
        bool $withItemTaxCodes = true,
        array $fiscalProfileOverrides = [],
    ): array {
        $user ??= User::factory()->create();

        $company ??= Company::query()->create([
            'name' => 'Empresa NFSE',
            'document_number' => '12345678000199',
            'address' => ['city' => 'Chapeco', 'state' => 'SC', 'city_code' => '4204202'],
            'created_by' => $user->id,
        ]);

        FiscalProfile::query()->updateOrCreate(
            ['company_id' => $company->id],
            array_replace([
                'tax_regime' => 'simples_nacional',
                'default_service_code' => $withServiceDefaults ? '01.01' : null,
                'default_municipal_tax_code' => $withServiceDefaults ? '01.01' : null,
                'default_nbs_code' => $withServiceDefaults ? '123456789' : null,
                'default_service_city_code' => '4204202',
                'iss_rate_default' => 5,
                'is_active' => true,
                'created_by' => $user->id,
            ], $fiscalProfileOverrides),
        );

        $customer ??= Partner::query()->create([
            'name' => 'Cliente NFSE',
            'document_type' => 'CNPJ',
            'document_number' => '22345678000188',
            'created_by' => $user->id,
        ]);

        $this->attachAddress($company, $customer, $user, 'Chapeco', 'SC', '4204202');

        $document = FiscalDocument::query()->create([
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'status' => Status::PENDING->value,
            'document_type' => DocumentModel::NFSE->value,
            'nfse_model' => $model->value,
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
            'municipal_tax_code' => $withItemTaxCodes ? '01.01' : null,
            'nbs_code' => $withItemTaxCodes ? '123456789' : null,
            'iss_exigibility' => '1',
            'iss_rate' => 5,
            'included_in_total' => true,
            'created_by' => $user->id,
        ]);

        return [$user, $document];
    }

    private function attachAddress(Company $company, Partner $customer, User $user, string $city, string $state, string $cityCode): void
    {
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
                'city' => $city,
                'state' => $state,
                'postal_code' => '89800-000',
                'city_code' => $cityCode,
                'created_by' => $user->id,
            ],
        );
    }
}
