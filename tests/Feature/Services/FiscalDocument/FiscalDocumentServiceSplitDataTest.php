<?php

namespace Tests\Feature\Services\FiscalDocument;

use App\Enum\FiscalDocument\BuyerPresenceIndicator;
use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\FreightModality;
use App\Enum\FiscalDocument\IssuePurpose;
use App\Enum\FiscalDocument\OperationNature;
use App\Enum\FiscalDocument\OperationType;
use App\Enum\FiscalDocument\Status;
use App\Models\Company;
use App\Models\FiscalProfile;
use App\Models\Partner;
use App\Models\User;
use App\Services\FiscalDocument\FiscalDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FiscalDocumentServiceSplitDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_writes_freight_payment_and_tax_metadata_to_split_table_on_create(): void
    {
        [$user, $company, $customer] = $this->makeScenario();

        $document = app(FiscalDocumentService::class)->create($this->basePayload($company, $customer, [
            'freight_data' => [
                'modalidade_frete' => FreightModality::SEM_FRETE->value,
                'transportador' => ['id' => '123'],
                'veiculo' => ['placa' => 'ABC1234', 'uf' => 'SP'],
            ],
            'payment_data' => ['formas_pagamento' => [['meio_pagamento' => '99', 'valor' => '0.00']]],
            'tax_data' => ['reference' => ['document_key' => 'NFE-REF']],
        ]), $user->id);

        $this->assertNotNull($document);

        $legacy = DB::table('fiscal_documents')->where('id', $document->id)->first();
        $this->assertNull($legacy->freight_data);
        $this->assertNull($legacy->payment_data);
        $this->assertNull($legacy->tax_data);

        $document = $document->fresh()->load('taxDetail');

        $this->assertSame([
            'modalidade_frete' => FreightModality::SEM_FRETE->value,
            'transportador' => ['id' => '123'],
            'veiculo' => ['placa' => 'ABC1234', 'uf' => 'SP'],
        ], $document->freight_data);
        $this->assertSame(['formas_pagamento' => [['meio_pagamento' => '99', 'valor' => '0.00']]], $document->payment_data);
        $this->assertSame(['reference' => ['document_key' => 'NFE-REF']], $document->tax_data);
    }

    public function test_it_writes_freight_payment_and_tax_metadata_to_split_table_on_update(): void
    {
        [$user, $company, $customer] = $this->makeScenario();
        $service = app(FiscalDocumentService::class);

        $document = $service->create($this->basePayload($company, $customer, [
            'freight_data' => ['modalidade_frete' => FreightModality::SEM_FRETE->value],
        ]), $user->id);

        $this->assertNotNull($document, $service->getMessage());

        $updated = $service->update($document, $this->basePayload($company, $customer, [
            'freight_data' => [
                'modalidade_frete' => FreightModality::FOB_DESTINATARIO->value,
                'transportador' => ['id' => '456'],
                'volumes' => [['quantidade' => '2', 'especie' => 'CAIXA']],
            ],
            'payment_data' => ['formas_pagamento' => [['meio_pagamento' => '01', 'valor' => '100.00']]],
            'tax_data' => ['intermediario' => ['indicador' => '0']],
        ]), $user->id);

        $this->assertNotNull($updated, $service->getMessage());

        $legacy = DB::table('fiscal_documents')->where('id', $document->id)->first();
        $this->assertNull($legacy->freight_data);
        $this->assertNull($legacy->payment_data);
        $this->assertNull($legacy->tax_data);

        $updated = $updated->fresh()->load('taxDetail');

        $this->assertSame([
            'modalidade_frete' => FreightModality::FOB_DESTINATARIO->value,
            'transportador' => ['id' => '456'],
            'volumes' => [['quantidade' => '2', 'especie' => 'CAIXA']],
        ], $updated->freight_data);
        $this->assertSame(['formas_pagamento' => [['meio_pagamento' => '01', 'valor' => '100.00']]], $updated->payment_data);
        $this->assertSame(['intermediario' => ['indicador' => '0']], $updated->tax_data);
    }

    public function test_it_writes_split_data_on_update_when_document_type_is_not_submitted(): void
    {
        [$user, $company, $customer] = $this->makeScenario();
        $service = app(FiscalDocumentService::class);

        $document = $service->create($this->basePayload($company, $customer), $user->id);

        $this->assertNotNull($document, $service->getMessage());

        $payload = $this->basePayload($company, $customer, [
            'freight_data' => [
                'modalidade_frete' => FreightModality::FOB_DESTINATARIO->value,
                'icms_retido' => ['valor_servico' => '10.00'],
            ],
            'payment_data' => ['formas_pagamento' => [['meio_pagamento' => '03', 'valor' => '50.00']]],
            'tax_data' => ['reference' => ['document_key' => 'NFE-SEM-TIPO']],
        ]);
        unset($payload['document_type']);

        $updated = $service->update($document, $payload, $user->id);

        $this->assertNotNull($updated, $service->getMessage());

        $legacy = DB::table('fiscal_documents')->where('id', $document->id)->first();
        $this->assertNull($legacy->freight_data);
        $this->assertNull($legacy->payment_data);
        $this->assertNull($legacy->tax_data);

        $updated = $updated->fresh()->load('taxDetail');

        $this->assertSame([
            'modalidade_frete' => FreightModality::FOB_DESTINATARIO->value,
            'icms_retido' => ['valor_servico' => '10.00'],
        ], $updated->freight_data);
        $this->assertSame(['formas_pagamento' => [['meio_pagamento' => '03', 'valor' => '50.00']]], $updated->payment_data);
        $this->assertSame(['reference' => ['document_key' => 'NFE-SEM-TIPO']], $updated->tax_data);
    }

    /**
     * @return array{0: User, 1: Company, 2: Partner}
     */
    private function makeScenario(): array
    {
        $user = User::factory()->create();
        $company = Company::query()->create([
            'name' => 'Empresa Fiscal',
            'document_number' => '12345678000199',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);
        $customer = Partner::query()->create([
            'name' => 'Cliente Fiscal',
            'document_type' => 'CNPJ',
            'document_number' => '22345678000188',
            'created_by' => $user->id,
        ]);

        FiscalProfile::query()->create([
            'company_id' => $company->id,
            'tax_regime' => 'simples_nacional',
            'cfop_rules' => [
                OperationNature::VENDA_DENTRO_ESTADO->value => [
                    'default_cfop' => '5102',
                ],
            ],
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        return [$user, $company, $customer];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function basePayload(Company $company, Partner $customer, array $overrides = []): array
    {
        return [
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'status' => Status::PENDING->value,
            'document_type' => DocumentModel::NFE->value,
            'issued_at' => now()->toDateString(),
            'movement_at' => now()->toDateString(),
            'operation_nature' => OperationNature::VENDA_DENTRO_ESTADO->value,
            'operation_type' => OperationType::SAIDA->value,
            'issue_purpose' => IssuePurpose::NORMAL->value,
            'is_final_consumer' => true,
            'buyer_presence_indicator' => BuyerPresenceIndicator::NAO_SE_APLICA->value,
            'freight_data' => ['modalidade_frete' => FreightModality::SEM_FRETE->value],
            ...$overrides,
        ];
    }
}
