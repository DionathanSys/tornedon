<?php

namespace Tests\Feature\Services\FiscalDocument\Actions;

use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\NfeStatus;
use App\Enum\FiscalDocument\NfseModel;
use App\Enum\FiscalDocument\Status;
use App\Enum\FiscalDocument\IssuerType;
use App\Enum\FiscalDocument\MunicipalTaxOperationType;
use App\Enum\FiscalDocument\NationalWithholdingType;
use App\Models\Address;
use App\Models\Company;
use App\Models\CompanyPartner;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentItem;
use App\Models\FiscalProfile;
use App\Models\Partner;
use App\Models\Service;
use App\Models\User;
use App\Services\FiscalDocument\Actions\BuildNfseNacionalPayloadAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuildNfseNacionalPayloadActionTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------
    // Success scenarios
    // ------------------------------------------------------------------

    public function test_builds_valid_payload_with_cnpj_taker(): void
    {
        $document = $this->createReadyDocument();
        $action   = new BuildNfseNacionalPayloadAction();
        $payload  = $action->build($document);

        $this->assertNotNull($payload, $action->getMessage() ?? 'Payload returned null');

        // Root fields
        $this->assertArrayHasKey('data_emissao', $payload);
        $this->assertArrayHasKey('numero', $payload);
        $this->assertArrayHasKey('serie', $payload);
        $this->assertSame(IssuerType::PROVIDER->value, $payload['tipo_emitente']);
        $this->assertArrayNotHasKey('identificacao', $payload);

        // Tomador
        $tomador = $payload['tomador'];
        $this->assertSame('12345678000199', $tomador['cnpj']);
        $this->assertArrayNotHasKey('tipo_destinatario', $tomador);
        $this->assertArrayNotHasKey('cpf', $tomador);

        // Endereço
        $endereco = $tomador['endereco'];
        $this->assertSame('Rua Teste', $endereco['logradouro']);
        $this->assertSame('100', $endereco['numero']);
        $this->assertSame('Centro', $endereco['bairro']);
        $this->assertSame('SC', $endereco['uf']);

        // Serviço
        $servico = $payload['servico'];
        $this->assertArrayHasKey('codigo', $servico);
        $this->assertArrayHasKey('codigo_nbs', $servico);
        $this->assertSame(100.0, $servico['valor_servicos']);
        $this->assertArrayHasKey('discriminacao', $servico);
        $this->assertArrayNotHasKey('codigo_tributacao_municipio', $servico);
        $this->assertArrayNotHasKey('valor_recebido', $servico);
        $this->assertSame('4204202', $servico['endereco_local_prestacao']['codigo_municipio_prestacao']);

        // Tributos municipais
        $tribMun = $servico['tributos_municipais'];
        $this->assertSame(MunicipalTaxOperationType::TAXABLE_IN_MUNICIPALITY->value, $tribMun['tipo_operacao']);
        $this->assertArrayNotHasKey('responsavel_retencao', $tribMun);

        // Tributos nacionais
        $tribNac = $servico['tributos_nacionais'];
        $this->assertArrayHasKey('cst', $tribNac);
        $this->assertSame(NationalWithholdingType::NOT_WITHHELD->value, $tribNac['tipo_retencao']);
    }

    public function test_builds_valid_payload_with_cpf_taker(): void
    {
        $document = $this->createReadyDocument(customerDocNumber: '12345678901');
        $action   = new BuildNfseNacionalPayloadAction();
        $payload  = $action->build($document);

        $this->assertNotNull($payload, $action->getMessage() ?? 'Payload returned null');
        $this->assertSame('12345678901', $payload['tomador']['cpf']);
        $this->assertArrayNotHasKey('cnpj', $payload['tomador']);
    }

    public function test_uses_fiscal_profile_cst_default(): void
    {
        $document = $this->createReadyDocument(cstDefault: '06');
        $action   = new BuildNfseNacionalPayloadAction();
        $payload  = $action->build($document);

        $this->assertNotNull($payload);
        $this->assertSame('06', $payload['servico']['tributos_nacionais']['cst']);
    }

    public function test_includes_regime_apuracao_when_configured(): void
    {
        $document = $this->createReadyDocument(regimeApuracao: '1');
        $action   = new BuildNfseNacionalPayloadAction();
        $payload  = $action->build($document);

        $this->assertNotNull($payload);
        $this->assertSame('1', $payload['regime_apuracao']);
    }

    public function test_includes_zeroed_tributos_totais_when_not_informed(): void
    {
        $document = $this->createReadyDocument();
        $action   = new BuildNfseNacionalPayloadAction();
        $payload  = $action->build($document);

        $this->assertNotNull($payload);
        $this->assertSame([
            'percentual_tributos_federais' => 0.0,
            'valor_tributos_federais' => 0.0,
            'percentual_tributos_estaduais' => 0.0,
            'valor_tributos_estaduais' => 0.0,
            'percentual_tributos_municipais' => 5.0,
            'valor_tributos_municipais' => 5.0,
            'percentual_tributos_simples_nacional' => 0.0,
        ], $payload['servico']['tributos_totais']);
    }

    public function test_me_epp_includes_tributos_totais_from_tax_data_when_informed(): void
    {
        $document = $this->createReadyDocument(specialTaxRegime: '6');
        $document->items()->update([
            'tax_data' => [
                'percentual_tributos_simples_nacional' => 6.5,
            ],
        ]);
        $document->unsetRelation('items');

        $action   = new BuildNfseNacionalPayloadAction();
        $payload  = $action->build($document);

        $this->assertNotNull($payload);
        $this->assertSame(6.5, $payload['servico']['tributos_totais']['percentual_tributos_simples_nacional']);
    }

    public function test_omits_null_values_from_payload(): void
    {
        $document = $this->createReadyDocument();
        $action   = new BuildNfseNacionalPayloadAction();
        $payload  = $action->build($document);

        $this->assertNotNull($payload);
        $this->assertNoNullValues($payload);
    }

    public function test_data_emissao_uses_iso8601_with_timezone(): void
    {
        $document = $this->createReadyDocument();
        $action   = new BuildNfseNacionalPayloadAction();
        $payload  = $action->build($document);

        $this->assertNotNull($payload);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            $payload['data_emissao']
        );
    }

    public function test_it_uses_document_issued_at_instead_of_now(): void
    {
        $document = $this->createReadyDocument();

        $action  = new BuildNfseNacionalPayloadAction();
        $payload = $action->execute($document->fresh('items', 'company', 'customer', 'fiscalProfile'));

        $this->assertNotNull($payload);
        $this->assertStringStartsWith($document->issued_at->format('Y-m-d'), (string) data_get($payload, 'data_emissao'));
    }

    public function test_includes_discount_when_present(): void
    {
        $document = $this->createReadyDocument(discountAmount: 10.0);
        $action   = new BuildNfseNacionalPayloadAction();
        $payload  = $action->build($document);

        $this->assertNotNull($payload);
        $this->assertSame(10.0, $payload['servico']['valor_desconto_incondicionado']);
    }

    public function test_nacional_uses_municipal_tax_code_even_when_internal_service_code_exists(): void
    {
        $document = $this->createReadyDocument();
        $service = Service::query()->create([
            'company_id' => $document->company_id,
            'service_code' => '01.02',
            'municipal_tax_code' => '405',
            'name' => 'Servico nacional',
            'price' => 100,
            'is_active' => true,
            'created_by' => $document->created_by,
        ]);

        $document->items()->update([
            'service_id' => $service->id,
            'municipal_tax_code' => '405',
        ]);
        $document->unsetRelation('items');

        $action = new BuildNfseNacionalPayloadAction();
        $payload = $action->build($document);

        $this->assertNotNull($payload);
        $this->assertSame('405000', $payload['servico']['codigo']);
        $this->assertArrayNotHasKey('codigo_tributacao_municipio', $payload['servico']);
    }

    public function test_nacional_uses_service_city_code_for_local_prestacao_instead_of_customer_city(): void
    {
        $document = $this->createReadyDocument();

        $companyPartner = CompanyPartner::where('company_id', $document->company_id)
            ->where('partner_id', $document->customer_id)
            ->first();

        Address::where('company_partner_id', $companyPartner->id)->update([
            'city' => 'Salto',
            'state' => 'SP',
            'city_code' => '3545209',
        ]);

        $document->unsetRelation('customer');

        $action = new BuildNfseNacionalPayloadAction();
        $payload = $action->build($document);

        $this->assertNotNull($payload);
        $this->assertSame('4204202', $payload['servico']['endereco_local_prestacao']['codigo_municipio_prestacao']);
    }

    public function test_it_prefers_customer_address_from_document_company(): void
    {
        $document = $this->createReadyDocument();

        $otherCompany = Company::query()->create([
            'name' => 'Outra Empresa',
            'document_number' => '11345678000155',
            'address' => [
                'city' => 'Chapeco',
                'state' => 'SC',
                'city_code' => '4204202',
            ],
            'created_by' => $document->created_by,
        ]);

        $otherCompanyPartner = CompanyPartner::query()->create([
            'company_id' => $otherCompany->id,
            'partner_id' => $document->customer_id,
            'type' => ['customer'],
            'is_active' => true,
        ]);

        Address::query()->create([
            'company_partner_id' => $otherCompanyPartner->id,
            'street' => 'Rua Antiga',
            'number' => '947-E',
            'neighborhood' => 'Centro',
            'city' => 'Chapeco',
            'state' => 'SC',
            'postal_code' => '89800-000',
            'city_code' => '4204202',
            'created_by' => $document->created_by,
        ]);

        $document->unsetRelation('customer');

        $action = new BuildNfseNacionalPayloadAction();
        $payload = $action->build($document);

        $this->assertNotNull($payload, $action->getMessage() ?? 'Payload returned null');
        $this->assertSame('100', data_get($payload, 'tomador.endereco.numero'));
    }

    public function test_me_epp_without_tributos_totais_data_generates_zeroed_totals(): void
    {
        $document = $this->createReadyDocument(specialTaxRegime: '6');
        $action   = new BuildNfseNacionalPayloadAction();
        $payload  = $action->build($document);

        $this->assertNotNull($payload);
        $this->assertSame([
            'percentual_tributos_federais' => 0.0,
            'valor_tributos_federais' => 0.0,
            'percentual_tributos_estaduais' => 0.0,
            'valor_tributos_estaduais' => 0.0,
            'percentual_tributos_municipais' => 0.0,
            'valor_tributos_municipais' => 0.0,
            'percentual_tributos_simples_nacional' => 0.0,
        ], $payload['servico']['tributos_totais']);
    }

    // ------------------------------------------------------------------
    // Failure scenarios
    // ------------------------------------------------------------------

    public function test_it_fails_when_rps_series_is_missing(): void
    {
        $document = $this->createReadyDocument();
        $document->update(['rps_series' => null]);

        $action  = new BuildNfseNacionalPayloadAction();
        $payload = $action->build($document);

        $this->assertNull($payload);
        $this->assertSame('NFS-e nacional requer série RPS válida para emissão.', $action->getMessage());
    }

    public function test_fails_when_uf_is_ex(): void
    {
        $document = $this->createReadyDocument(customerState: 'EX');
        $action   = new BuildNfseNacionalPayloadAction();
        $payload  = $action->build($document);

        $this->assertNull($payload);
        $this->assertStringContainsString('EX', $action->getMessage());
    }

    public function test_fails_when_no_items(): void
    {
        $document = $this->createReadyDocument();
        $document->items()->delete();
        $document->unsetRelation('items');

        $action  = new BuildNfseNacionalPayloadAction();
        $payload = $action->build($document);

        $this->assertNull($payload);
    }

    public function test_fails_when_municipal_tax_code_empty(): void
    {
        $document = $this->createReadyDocument();
        $document->items()->update(['municipal_tax_code' => null]);
        $document->unsetRelation('items');

        $profile = FiscalProfile::where('company_id', $document->company_id)->first();
        $profile->update([
            'default_municipal_tax_code' => null,
        ]);
        $document->unsetRelation('fiscalProfile');

        $action  = new BuildNfseNacionalPayloadAction();
        $payload = $action->build($document);

        $this->assertNull($payload);
    }

    public function test_fails_when_nbs_code_empty(): void
    {
        $document = $this->createReadyDocument();
        $document->items()->update(['nbs_code' => null]);
        $document->unsetRelation('items');

        $profile = FiscalProfile::where('company_id', $document->company_id)->first();
        $profile->update(['default_nbs_code' => null]);
        $document->unsetRelation('fiscalProfile');

        $action  = new BuildNfseNacionalPayloadAction();
        $payload = $action->build($document);

        $this->assertNull($payload);
    }

    public function test_fails_when_valor_servicos_zero(): void
    {
        $document = $this->createReadyDocument();
        $document->items()->update(['total_price' => 0]);
        $document->unsetRelation('items');

        $action  = new BuildNfseNacionalPayloadAction();
        $payload = $action->build($document);

        $this->assertNull($payload);
    }

    public function test_fails_when_customer_address_missing(): void
    {
        $document = $this->createReadyDocument();

        $companyPartner = CompanyPartner::where('company_id', $document->company_id)
            ->where('partner_id', $document->customer_id)
            ->first();
        Address::where('company_partner_id', $companyPartner->id)->delete();
        $document->unsetRelation('customer');

        $action  = new BuildNfseNacionalPayloadAction();
        $payload = $action->build($document);

        $this->assertNull($payload);
    }

    // ------------------------------------------------------------------
    // Fixture
    // ------------------------------------------------------------------

    private function createReadyDocument(
        string $customerDocNumber = '12345678000199',
        string $customerState = 'SC',
        ?string $cstDefault = null,
        ?string $regimeApuracao = null,
        ?string $specialTaxRegime = null,
        float $discountAmount = 0,
    ): FiscalDocument {
        $user = User::factory()->create();

        $company = Company::query()->create([
            'name'            => 'Empresa Nacional',
            'document_number' => '99345678000100',
            'address'         => [
                'city'      => 'Chapeco',
                'state'     => 'SC',
                'city_code' => '4204202',
            ],
            'created_by' => $user->id,
        ]);

        FiscalProfile::query()->create([
            'company_id'                    => $company->id,
            'tax_regime'                    => 'simples_nacional',
            'default_service_code'          => '01.01',
            'default_municipal_tax_code'    => '01.01',
            'default_nbs_code'              => '123456789',
            'default_service_city_code'     => '4204202',
            'iss_rate_default'              => 5,
            'nfse_special_tax_regime'       => $specialTaxRegime,
            'nfse_nacional_cst_default'     => $cstDefault,
            'nfse_nacional_regime_apuracao' => $regimeApuracao,
            'is_active'                     => true,
            'created_by'                    => $user->id,
        ]);

        $customer = Partner::query()->create([
            'name'            => 'Cliente Nacional',
            'document_type'   => strlen(preg_replace('/\D/', '', $customerDocNumber)) === 14 ? 'CNPJ' : 'CPF',
            'document_number' => $customerDocNumber,
            'created_by'      => $user->id,
        ]);

        $companyPartner = CompanyPartner::query()->create([
            'company_id' => $company->id,
            'partner_id' => $customer->id,
            'type'       => ['customer'],
            'is_active'  => true,
        ]);

        Address::query()->create([
            'company_partner_id' => $companyPartner->id,
            'street'             => 'Rua Teste',
            'number'             => '100',
            'neighborhood'       => 'Centro',
            'city'               => 'Chapeco',
            'state'              => $customerState,
            'postal_code'        => '89800000',
            'city_code'          => '4204202',
            'created_by'         => $user->id,
        ]);

        $document = FiscalDocument::query()->create([
            'customer_id'   => $customer->id,
            'company_id'    => $company->id,
            'status'        => Status::PENDING->value,
            'document_type' => DocumentModel::NFSE->value,
            'nfse_model'    => NfseModel::NACIONAL->value,
            'issued_at'     => now()->subDay()->toDateString(),
            'movement_at'   => now()->subDay()->toDateString(),
            'nfse_status'   => NfeStatus::PENDING->value,
            'rps_number'    => '1',
            'rps_series'    => '1',
            'created_by'    => $user->id,
            'updated_by'    => $user->id,
        ]);

        FiscalDocumentItem::query()->create([
            'fiscal_document_id' => $document->id,
            'item_number'        => 1,
            'description'        => 'Servico de teste nacional',
            'quantity'           => 1,
            'unit_of_measure'    => 'UN',
            'unit_price'         => 100,
            'total_price'        => 100,
            'discount_amount'    => $discountAmount,
            'municipal_tax_code' => '01.01',
            'nbs_code'           => '123456789',
            'iss_rate'           => 5,
            'included_in_total'  => true,
            'created_by'         => $user->id,
        ]);

        return $document;
    }

    private function assertNoNullValues(array $data, string $prefix = ''): void
    {
        foreach ($data as $key => $value) {
            $path = $prefix !== '' ? "{$prefix}.{$key}" : $key;

            $this->assertNotNull($value, "Payload contains null at '{$path}'");

            if (is_array($value)) {
                $this->assertNoNullValues($value, $path);
            }
        }
    }
}
