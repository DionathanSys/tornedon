<?php

namespace Tests\Feature\Services\FiscalDocument\Actions;

use App\Enum\FiscalDocument\BuyerPresenceIndicator;
use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\FreightModality;
use App\Enum\FiscalDocument\IssuePurpose;
use App\Enum\FiscalDocument\OperationNature;
use App\Enum\FiscalDocument\OperationType;
use App\Enum\FiscalDocument\Status;
use App\Models\Address;
use App\Models\Company;
use App\Models\CompanyPartner;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentItem;
use App\Models\FiscalProfile;
use App\Models\Partner;
use App\Models\Product;
use App\Models\User;
use App\Services\FiscalDocument\Actions\BuildNfePayloadAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BuildNfePayloadActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_falls_back_to_fiscal_snapshot_for_cfop_and_imposto(): void
    {
        $user = User::factory()->create();

        $company = Company::query()->create([
            'name' => 'Empresa Payload',
            'document_number' => '12345678000199',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);

        $customer = Partner::query()->create([
            'name' => 'Fornecedor Payload',
            'document_type' => 'CNPJ',
            'document_number' => '22345678000188',
            'created_by' => $user->id,
        ]);

        $document = FiscalDocument::query()->create([
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'status' => Status::PENDING->value,
            'document_type' => DocumentModel::NFE->value,
            'issued_at' => now()->toDateString(),
            'movement_at' => now()->toDateString(),
            'document_number' => '100',
            'document_series' => '1',
            'operation_nature' => OperationNature::DEVOLUCAO_COMPRA->value,
            'operation_type' => OperationType::SAIDA->value,
            'issue_purpose' => IssuePurpose::DEVOLUCAO->value,
            'is_final_consumer' => false,
            'buyer_presence_indicator' => BuyerPresenceIndicator::OUTROS->value,
            'freight_data' => [
                'modalidade_frete' => FreightModality::SEM_FRETE->value,
            ],
            'created_by' => $user->id,
        ]);

        $product = Product::query()->create([
            'company_id' => $company->id,
            'created_by' => $user->id,
            'product_code' => 'PRD-PAY-001',
            'name' => 'Produto Payload',
            'unit' => \App\Enum\Product\Unit::UN->value,
            'origin_sale_price' => \App\Enum\Product\OriginSalePrice::FREE->value,
            'sale_price_value' => 100,
            'is_active' => true,
        ]);

        FiscalDocumentItem::query()->create([
            'fiscal_document_id' => $document->id,
            'product_id' => $product->id,
            'product_code' => $product->product_code,
            'description' => $product->name,
            'item_number' => 1,
            'product_origin' => '0',
            'ncm_code' => '84733049',
            'quantity' => 1,
            'unit_of_measure' => 'UN',
            'unit_price' => 100,
            'total_price' => 100,
            'included_in_total' => true,
            'tax_data' => [],
            'fiscal_snapshot' => [
                'cfop' => '5202',
                'cst_icms' => '00',
                'aliquota_icms' => 18,
                'cst_pis' => '01',
                'aliquota_pis' => 1.65,
                'cst_cofins' => '01',
                'aliquota_cofins' => 7.6,
            ],
            'created_by' => $user->id,
        ]);

        $payload = app(BuildNfePayloadAction::class)->execute($document->fresh('items.product', 'customer.address', 'company'));

        $this->assertNotNull($payload);
        $this->assertSame('5202', $payload['itens'][0]['cfop']);
        $this->assertIsArray($payload['itens'][0]['imposto']);
        $this->assertSame('00', $payload['itens'][0]['imposto']['icms']['situacao_tributaria']);
        $this->assertSame('01', $payload['itens'][0]['imposto']['pis']['situacao_tributaria']);
        $this->assertSame('01', $payload['itens'][0]['imposto']['cofins']['situacao_tributaria']);
    }

    public function test_it_reloads_items_from_database_before_building_payload(): void
    {
        $user = User::factory()->create();

        $company = Company::query()->create([
            'name' => 'Empresa Refresh',
            'document_number' => '12345678000199',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);

        $customer = Partner::query()->create([
            'name' => 'Fornecedor Refresh',
            'document_type' => 'CNPJ',
            'document_number' => '22345678000188',
            'created_by' => $user->id,
        ]);

        $document = FiscalDocument::query()->create([
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'status' => Status::PENDING->value,
            'document_type' => DocumentModel::NFE->value,
            'issued_at' => now()->toDateString(),
            'movement_at' => now()->toDateString(),
            'document_number' => '101',
            'document_series' => '1',
            'operation_nature' => OperationNature::DEVOLUCAO_COMPRA->value,
            'operation_type' => OperationType::SAIDA->value,
            'issue_purpose' => IssuePurpose::DEVOLUCAO->value,
            'is_final_consumer' => false,
            'buyer_presence_indicator' => BuyerPresenceIndicator::OUTROS->value,
            'freight_data' => [
                'modalidade_frete' => FreightModality::SEM_FRETE->value,
            ],
            'created_by' => $user->id,
        ]);

        $product = Product::query()->create([
            'company_id' => $company->id,
            'created_by' => $user->id,
            'product_code' => 'PRD-REF-001',
            'name' => 'Produto Refresh',
            'unit' => \App\Enum\Product\Unit::UN->value,
            'origin_sale_price' => \App\Enum\Product\OriginSalePrice::FREE->value,
            'sale_price_value' => 100,
            'is_active' => true,
        ]);

        $item = FiscalDocumentItem::query()->create([
            'fiscal_document_id' => $document->id,
            'product_id' => $product->id,
            'product_code' => $product->product_code,
            'description' => $product->name,
            'item_number' => 1,
            'product_origin' => '0',
            'ncm_code' => '84733049',
            'quantity' => 1,
            'unit_of_measure' => 'UN',
            'unit_price' => 100,
            'total_price' => 100,
            'included_in_total' => true,
            'tax_data' => [],
            'fiscal_snapshot' => [],
            'created_by' => $user->id,
        ]);

        $staleDocument = FiscalDocument::query()->with('items.product', 'customer.address', 'company')->findOrFail($document->id);

        $item->update([
            'cfop_code' => '5202',
            'tax_data' => [
                'imposto' => [
                    'icms' => ['situacao_tributaria' => '00'],
                    'pis' => ['situacao_tributaria' => '01'],
                    'cofins' => ['situacao_tributaria' => '01'],
                ],
            ],
        ]);

        $payload = app(BuildNfePayloadAction::class)->execute($staleDocument);

        $this->assertNotNull($payload);
        $this->assertSame('5202', $payload['itens'][0]['cfop']);
        $this->assertSame('00', $payload['itens'][0]['imposto']['icms']['situacao_tributaria']);
    }

    public function test_it_uses_a_valid_timestamp_for_emission_and_movement_dates(): void
    {
        Carbon::setTestNow('2026-06-08 09:29:04');

        $user = User::factory()->create();

        $company = Company::query()->create([
            'name' => 'Empresa Datas',
            'document_number' => '12345678000199',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);

        $customer = Partner::query()->create([
            'name' => 'Cliente Datas',
            'document_type' => 'CNPJ',
            'document_number' => '22345678000188',
            'created_by' => $user->id,
        ]);

        $document = FiscalDocument::query()->create([
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'status' => Status::PENDING->value,
            'document_type' => DocumentModel::NFE->value,
            'issued_at' => '2026-06-08',
            'movement_at' => '2026-06-08',
            'document_number' => '102',
            'document_series' => '1',
            'operation_nature' => OperationNature::DEVOLUCAO_COMPRA->value,
            'operation_type' => OperationType::SAIDA->value,
            'issue_purpose' => IssuePurpose::DEVOLUCAO->value,
            'is_final_consumer' => false,
            'buyer_presence_indicator' => BuyerPresenceIndicator::OUTROS->value,
            'freight_data' => [
                'modalidade_frete' => FreightModality::SEM_FRETE->value,
            ],
            'created_by' => $user->id,
        ]);

        $product = Product::query()->create([
            'company_id' => $company->id,
            'created_by' => $user->id,
            'product_code' => 'PRD-DAT-001',
            'name' => 'Produto Datas',
            'unit' => \App\Enum\Product\Unit::UN->value,
            'origin_sale_price' => \App\Enum\Product\OriginSalePrice::FREE->value,
            'sale_price_value' => 100,
            'is_active' => true,
        ]);

        FiscalDocumentItem::query()->create([
            'fiscal_document_id' => $document->id,
            'product_id' => $product->id,
            'product_code' => $product->product_code,
            'description' => $product->name,
            'item_number' => 1,
            'product_origin' => '0',
            'ncm_code' => '84733049',
            'cfop_code' => '5202',
            'quantity' => 1,
            'unit_of_measure' => 'UN',
            'unit_price' => 100,
            'total_price' => 100,
            'included_in_total' => true,
            'tax_data' => [
                'imposto' => [
                    'icms' => ['situacao_tributaria' => '00'],
                    'pis' => ['situacao_tributaria' => '01'],
                    'cofins' => ['situacao_tributaria' => '01'],
                ],
            ],
            'created_by' => $user->id,
        ]);

        $payload = app(BuildNfePayloadAction::class)->execute($document->fresh('items.product', 'customer.address', 'company'));

        $this->assertNotNull($payload);
        $this->assertSame('2026-06-08T09:29:04-03:00', $payload['data_emissao']);
        $this->assertSame($payload['data_emissao'], $payload['data_entrada_saida']);

        Carbon::setTestNow();
    }

    public function test_it_preserves_preview_number_set_in_memory_while_reloading_relations(): void
    {
        $user = User::factory()->create();

        $company = Company::query()->create([
            'name' => 'Empresa Preview',
            'document_number' => '12345678000199',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);

        $customer = Partner::query()->create([
            'name' => 'Fornecedor Preview',
            'document_type' => 'CNPJ',
            'document_number' => '22345678000188',
            'created_by' => $user->id,
        ]);

        $document = FiscalDocument::query()->create([
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'status' => Status::PENDING->value,
            'document_type' => DocumentModel::NFE->value,
            'issued_at' => now()->toDateString(),
            'movement_at' => now()->toDateString(),
            'document_number' => null,
            'document_series' => null,
            'operation_nature' => OperationNature::DEVOLUCAO_COMPRA->value,
            'operation_type' => OperationType::SAIDA->value,
            'issue_purpose' => IssuePurpose::DEVOLUCAO->value,
            'is_final_consumer' => false,
            'buyer_presence_indicator' => BuyerPresenceIndicator::OUTROS->value,
            'freight_data' => [
                'modalidade_frete' => FreightModality::SEM_FRETE->value,
            ],
            'created_by' => $user->id,
        ]);

        $product = Product::query()->create([
            'company_id' => $company->id,
            'created_by' => $user->id,
            'product_code' => 'PRD-PREV-001',
            'name' => 'Produto Preview',
            'unit' => \App\Enum\Product\Unit::UN->value,
            'origin_sale_price' => \App\Enum\Product\OriginSalePrice::FREE->value,
            'sale_price_value' => 100,
            'is_active' => true,
        ]);

        FiscalDocumentItem::query()->create([
            'fiscal_document_id' => $document->id,
            'product_id' => $product->id,
            'product_code' => $product->product_code,
            'description' => $product->name,
            'item_number' => 1,
            'product_origin' => '0',
            'ncm_code' => '84733049',
            'cfop_code' => '5202',
            'quantity' => 1,
            'unit_of_measure' => 'UN',
            'unit_price' => 100,
            'total_price' => 100,
            'included_in_total' => true,
            'tax_data' => [
                'imposto' => [
                    'icms' => ['situacao_tributaria' => '00'],
                    'pis' => ['situacao_tributaria' => '01'],
                    'cofins' => ['situacao_tributaria' => '01'],
                ],
            ],
            'created_by' => $user->id,
        ]);

        $document->document_number = '1';
        $document->document_series = '1';

        $payload = app(BuildNfePayloadAction::class)->execute($document);

        $this->assertNotNull($payload);
        $this->assertSame(1, $payload['numero']);
        $this->assertSame('1', $payload['serie']);
    }

    public function test_it_includes_referenced_nfe_when_purchase_return_origin_key_exists(): void
    {
        $user = User::factory()->create();

        $company = Company::query()->create([
            'name' => 'Empresa Referencia',
            'document_number' => '12345678000199',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);

        $customer = Partner::query()->create([
            'name' => 'Fornecedor Referencia',
            'document_type' => 'CNPJ',
            'document_number' => '22345678000188',
            'created_by' => $user->id,
        ]);

        $document = FiscalDocument::query()->create([
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'status' => Status::PENDING->value,
            'document_type' => DocumentModel::NFE->value,
            'issued_at' => now()->toDateString(),
            'movement_at' => now()->toDateString(),
            'document_number' => '5',
            'document_series' => '1',
            'operation_nature' => OperationNature::DEVOLUCAO_COMPRA->value,
            'operation_type' => OperationType::SAIDA->value,
            'issue_purpose' => IssuePurpose::DEVOLUCAO->value,
            'is_final_consumer' => false,
            'buyer_presence_indicator' => BuyerPresenceIndicator::OUTROS->value,
            'freight_data' => [
                'modalidade_frete' => FreightModality::SEM_FRETE->value,
            ],
            'tax_data' => [
                'purchase_return_origin' => [
                    'document_key' => '42260304152592000460550020000182011223433135',
                ],
            ],
            'created_by' => $user->id,
        ]);

        $product = Product::query()->create([
            'company_id' => $company->id,
            'created_by' => $user->id,
            'product_code' => 'PRD-REFNFE-001',
            'name' => 'Produto Referenciado',
            'unit' => \App\Enum\Product\Unit::UN->value,
            'origin_sale_price' => \App\Enum\Product\OriginSalePrice::FREE->value,
            'sale_price_value' => 100,
            'is_active' => true,
        ]);

        FiscalDocumentItem::query()->create([
            'fiscal_document_id' => $document->id,
            'product_id' => $product->id,
            'product_code' => $product->product_code,
            'description' => $product->name,
            'item_number' => 1,
            'product_origin' => '0',
            'ncm_code' => '84733049',
            'cfop_code' => '5202',
            'quantity' => 1,
            'unit_of_measure' => 'UN',
            'unit_price' => 100,
            'total_price' => 100,
            'included_in_total' => true,
            'tax_data' => [
                'imposto' => [
                    'icms' => ['situacao_tributaria' => '00'],
                    'pis' => ['situacao_tributaria' => '04'],
                    'cofins' => ['situacao_tributaria' => '04'],
                ],
            ],
            'created_by' => $user->id,
        ]);

        $payload = app(BuildNfePayloadAction::class)->execute($document);

        $this->assertNotNull($payload);
        $this->assertSame(
            '42260304152592000460550020000182011223433135',
            data_get($payload, 'notas_referenciadas.0.nfe.chave')
        );
    }

    public function test_it_uses_net_taxable_base_when_rebuilding_taxes_from_snapshot(): void
    {
        $user = User::factory()->create();

        $company = Company::query()->create([
            'name' => 'Empresa Desconto',
            'document_number' => '12345678000199',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);

        $customer = Partner::query()->create([
            'name' => 'Cliente Desconto',
            'document_type' => 'CNPJ',
            'document_number' => '22345678000188',
            'created_by' => $user->id,
        ]);

        $document = FiscalDocument::query()->create([
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'status' => Status::PENDING->value,
            'document_type' => DocumentModel::NFE->value,
            'issued_at' => now()->toDateString(),
            'movement_at' => now()->toDateString(),
            'document_number' => '105',
            'document_series' => '1',
            'operation_nature' => OperationNature::VENDA_DENTRO_ESTADO->value,
            'operation_type' => OperationType::SAIDA->value,
            'issue_purpose' => IssuePurpose::NORMAL->value,
            'is_final_consumer' => false,
            'buyer_presence_indicator' => BuyerPresenceIndicator::OUTROS->value,
            'freight_data' => [
                'modalidade_frete' => FreightModality::SEM_FRETE->value,
            ],
            'created_by' => $user->id,
        ]);

        $product = Product::query()->create([
            'company_id' => $company->id,
            'created_by' => $user->id,
            'product_code' => 'PRD-DESC-001',
            'name' => 'Produto com Desconto',
            'unit' => \App\Enum\Product\Unit::UN->value,
            'origin_sale_price' => \App\Enum\Product\OriginSalePrice::FREE->value,
            'sale_price_value' => 100,
            'is_active' => true,
        ]);

        FiscalDocumentItem::query()->create([
            'fiscal_document_id' => $document->id,
            'product_id' => $product->id,
            'product_code' => $product->product_code,
            'description' => $product->name,
            'item_number' => 1,
            'product_origin' => '0',
            'ncm_code' => '84733049',
            'quantity' => 1,
            'unit_of_measure' => 'UN',
            'unit_price' => 100,
            'total_price' => 100,
            'discount_amount' => 15,
            'included_in_total' => true,
            'tax_data' => [],
            'fiscal_snapshot' => [
                'cfop' => '5102',
                'cst_icms' => '00',
                'aliquota_icms' => 18,
                'cst_pis' => '01',
                'aliquota_pis' => 1.65,
                'cst_cofins' => '01',
                'aliquota_cofins' => 7.6,
            ],
            'created_by' => $user->id,
        ]);

        $payload = app(BuildNfePayloadAction::class)->execute($document->fresh('items.product', 'customer.address', 'company'));

        $this->assertNotNull($payload);
        $this->assertSame('15.00', data_get($payload, 'itens.0.valor_desconto'));
        $this->assertSame(85.0, (float) data_get($payload, 'itens.0.imposto.icms.valor_base_calculo'));
        $this->assertSame(85.0, (float) data_get($payload, 'itens.0.imposto.pis.valor_base_calculo'));
        $this->assertSame(85.0, (float) data_get($payload, 'itens.0.imposto.cofins.valor_base_calculo'));
    }

    public function test_it_normalizes_freight_data_before_sending_payload(): void
    {
        $user = User::factory()->create();

        $company = Company::query()->create([
            'name' => 'Empresa Frete',
            'document_number' => '12345678000199',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);

        $customer = Partner::query()->create([
            'name' => 'Cliente Frete',
            'document_type' => 'CNPJ',
            'document_number' => '22345678000188',
            'created_by' => $user->id,
        ]);

        $carrier = Partner::query()->create([
            'name' => 'Transportadora Exemplo',
            'document_type' => 'CNPJ',
            'document_number' => '12345678000199',
            'state_tax_id' => '123456789',
            'created_by' => $user->id,
        ]);

        $companyPartner = CompanyPartner::query()->create([
            'partner_id' => $carrier->id,
            'company_id' => $company->id,
            'type' => ['supplier'],
            'is_active' => true,
        ]);

        Address::query()->create([
            'company_partner_id' => $companyPartner->id,
            'street' => 'Rua do Frete, 100',
            'number' => '100',
            'neighborhood' => 'Centro',
            'city' => 'Sao Paulo',
            'state' => 'SP',
            'country' => 'BRASIL',
            'postal_code' => '01001000',
            'city_code' => '3550308',
            'created_by' => $user->id,
        ]);

        $document = FiscalDocument::query()->create([
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'status' => Status::PENDING->value,
            'document_type' => DocumentModel::NFE->value,
            'issued_at' => now()->toDateString(),
            'movement_at' => now()->toDateString(),
            'document_number' => '110',
            'document_series' => '1',
            'operation_nature' => OperationNature::VENDA_DENTRO_ESTADO->value,
            'operation_type' => OperationType::SAIDA->value,
            'issue_purpose' => IssuePurpose::NORMAL->value,
            'is_final_consumer' => false,
            'buyer_presence_indicator' => BuyerPresenceIndicator::OUTROS->value,
            'freight_data' => [
                'modalidade_frete' => FreightModality::FOB_DESTINATARIO->value,
                'transportador' => [
                    'id' => $carrier->id,
                ],
                'icms_retido' => [
                    'valor_servico' => '150.00',
                    'base_calculo_retencao_icms' => '150.00',
                    'aliquota_retencao' => '12.00',
                    'valor_icms_retido' => '18.00',
                    'cfop' => '5353',
                    'codigo_municipio_ocorrencia_fato_gerador' => '3550308',
                ],
                'veiculo' => [
                    'placa' => 'ABC1D23',
                    'uf' => 'SP',
                    'rntc' => '12345678',
                ],
                'identificacao_vagao' => 'VAG-01',
                'identificacao_balsa' => 'BALSA-01',
                'volumes' => [
                    [
                        'quantidade' => '2',
                        'especie' => 'CAIXA',
                        'marca' => 'PADRAO',
                        'numero' => '10',
                        'peso_liquido' => '5.5',
                        'peso_bruto' => '6.1',
                        'lacres' => [
                            ['numero' => 'LACRE-1'],
                            ['numero' => ''],
                        ],
                    ],
                    [],
                ],
            ],
            'created_by' => $user->id,
        ]);

        $product = Product::query()->create([
            'company_id' => $company->id,
            'created_by' => $user->id,
            'product_code' => 'PRD-FRT-001',
            'name' => 'Produto Frete',
            'unit' => \App\Enum\Product\Unit::UN->value,
            'origin_sale_price' => \App\Enum\Product\OriginSalePrice::FREE->value,
            'sale_price_value' => 100,
            'is_active' => true,
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
                    'icms' => ['situacao_tributaria' => '00'],
                    'pis' => ['situacao_tributaria' => '01'],
                    'cofins' => ['situacao_tributaria' => '01'],
                ],
            ],
            'created_by' => $user->id,
        ]);

        $payload = app(BuildNfePayloadAction::class)->execute($document->fresh('items.product', 'customer.address', 'company'));

        $this->assertNotNull($payload);
        $this->assertSame(FreightModality::FOB_DESTINATARIO->value, data_get($payload, 'frete.modalidade_frete'));
        $this->assertSame('12345678000199', data_get($payload, 'frete.transportador.cnpj'));
        $this->assertNull(data_get($payload, 'frete.transportador.cpf'));
        $this->assertSame('Transportadora Exemplo', data_get($payload, 'frete.transportador.nome'));
        $this->assertSame('150.00', data_get($payload, 'frete.icms_retido.valor_servico'));
        $this->assertSame('ABC1D23', data_get($payload, 'frete.veiculo.placa'));
        $this->assertSame('VAG-01', data_get($payload, 'frete.identificacao_vagao'));
        $this->assertCount(1, data_get($payload, 'frete.volumes'));
        $this->assertCount(1, data_get($payload, 'frete.volumes.0.lacres'));
        $this->assertSame('LACRE-1', data_get($payload, 'frete.volumes.0.lacres.0.numero'));
    }

    public function test_it_defaults_intermediary_indicator_for_remote_sales(): void
    {
        $user = User::factory()->create();

        $company = Company::query()->create([
            'name' => 'Empresa E-commerce',
            'document_number' => '12345678000199',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);

        $customer = Partner::query()->create([
            'name' => 'Cliente Online',
            'document_type' => 'CNPJ',
            'document_number' => '22345678000188',
            'created_by' => $user->id,
        ]);

        $document = FiscalDocument::query()->create([
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'status' => Status::PENDING->value,
            'document_type' => DocumentModel::NFE->value,
            'issued_at' => now()->toDateString(),
            'movement_at' => now()->toDateString(),
            'document_number' => '111',
            'document_series' => '1',
            'operation_nature' => OperationNature::VENDA_DENTRO_ESTADO->value,
            'operation_type' => OperationType::SAIDA->value,
            'issue_purpose' => IssuePurpose::NORMAL->value,
            'is_final_consumer' => true,
            'buyer_presence_indicator' => BuyerPresenceIndicator::INTERNET->value,
            'freight_data' => [
                'modalidade_frete' => FreightModality::SEM_FRETE->value,
            ],
            'created_by' => $user->id,
        ]);

        $product = Product::query()->create([
            'company_id' => $company->id,
            'created_by' => $user->id,
            'product_code' => 'PRD-ECM-001',
            'name' => 'Produto Online',
            'unit' => \App\Enum\Product\Unit::UN->value,
            'origin_sale_price' => \App\Enum\Product\OriginSalePrice::FREE->value,
            'sale_price_value' => 100,
            'is_active' => true,
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
                    'icms' => ['situacao_tributaria' => '00'],
                    'pis' => ['situacao_tributaria' => '01'],
                    'cofins' => ['situacao_tributaria' => '01'],
                ],
            ],
            'created_by' => $user->id,
        ]);

        $payload = app(BuildNfePayloadAction::class)->execute($document->fresh('items.product', 'customer.address', 'company'));

        $this->assertNotNull($payload);
        $this->assertSame('0', data_get($payload, 'intermediario.indicador'));
    }

    public function test_it_includes_marketplace_identification_when_configured(): void
    {
        $user = User::factory()->create();

        $company = Company::query()->create([
            'name' => 'Empresa Marketplace',
            'document_number' => '12345678000199',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);

        $customer = Partner::query()->create([
            'name' => 'Cliente Marketplace',
            'document_type' => 'CNPJ',
            'document_number' => '22345678000188',
            'created_by' => $user->id,
        ]);

        $document = FiscalDocument::query()->create([
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'status' => Status::PENDING->value,
            'document_type' => DocumentModel::NFE->value,
            'issued_at' => now()->toDateString(),
            'movement_at' => now()->toDateString(),
            'document_number' => '112',
            'document_series' => '1',
            'operation_nature' => OperationNature::VENDA_DENTRO_ESTADO->value,
            'operation_type' => OperationType::SAIDA->value,
            'issue_purpose' => IssuePurpose::NORMAL->value,
            'is_final_consumer' => true,
            'buyer_presence_indicator' => BuyerPresenceIndicator::INTERNET->value,
            'freight_data' => [
                'modalidade_frete' => FreightModality::SEM_FRETE->value,
            ],
            'tax_data' => [
                'intermediario' => [
                    'indicador' => '1',
                    'cnpj' => '12.345.678/0001-90',
                    'identificacao' => 'SELLER-123',
                ],
            ],
            'created_by' => $user->id,
        ]);

        $product = Product::query()->create([
            'company_id' => $company->id,
            'created_by' => $user->id,
            'product_code' => 'PRD-MKP-001',
            'name' => 'Produto Marketplace',
            'unit' => \App\Enum\Product\Unit::UN->value,
            'origin_sale_price' => \App\Enum\Product\OriginSalePrice::FREE->value,
            'sale_price_value' => 100,
            'is_active' => true,
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
                    'icms' => ['situacao_tributaria' => '00'],
                    'pis' => ['situacao_tributaria' => '01'],
                    'cofins' => ['situacao_tributaria' => '01'],
                ],
            ],
            'created_by' => $user->id,
        ]);

        $payload = app(BuildNfePayloadAction::class)->execute($document->fresh('items.product', 'customer.address', 'company'));

        $this->assertNotNull($payload);
        $this->assertSame('1', data_get($payload, 'intermediario.indicador'));
        $this->assertSame('12345678000190', data_get($payload, 'intermediario.cnpj'));
        $this->assertSame('SELLER-123', data_get($payload, 'intermediario.identificacao'));
    }

    public function test_it_blocks_cst_for_simples_nacional_emitter(): void
    {
        $user = User::factory()->create();

        $company = Company::query()->create([
            'name' => 'Empresa Simples',
            'document_number' => '12345678000199',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);

        FiscalProfile::query()->create([
            'company_id' => $company->id,
            'tax_regime' => 'simples_nacional',
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        $customer = Partner::query()->create([
            'name' => 'Cliente Simples',
            'document_type' => 'CNPJ',
            'document_number' => '22345678000188',
            'created_by' => $user->id,
        ]);

        $document = FiscalDocument::query()->create([
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'status' => Status::PENDING->value,
            'document_type' => DocumentModel::NFE->value,
            'issued_at' => now()->toDateString(),
            'movement_at' => now()->toDateString(),
            'document_number' => '6',
            'document_series' => '1',
            'operation_nature' => OperationNature::DEVOLUCAO_COMPRA->value,
            'operation_type' => OperationType::SAIDA->value,
            'issue_purpose' => IssuePurpose::DEVOLUCAO->value,
            'is_final_consumer' => false,
            'buyer_presence_indicator' => BuyerPresenceIndicator::OUTROS->value,
            'freight_data' => [
                'modalidade_frete' => FreightModality::SEM_FRETE->value,
            ],
            'created_by' => $user->id,
        ]);

        $product = Product::query()->create([
            'company_id' => $company->id,
            'created_by' => $user->id,
            'product_code' => 'PRD-SN-001',
            'name' => 'Produto Simples',
            'unit' => \App\Enum\Product\Unit::UN->value,
            'origin_sale_price' => \App\Enum\Product\OriginSalePrice::FREE->value,
            'sale_price_value' => 100,
            'is_active' => true,
        ]);

        FiscalDocumentItem::query()->create([
            'fiscal_document_id' => $document->id,
            'product_id' => $product->id,
            'product_code' => $product->product_code,
            'description' => $product->name,
            'item_number' => 1,
            'product_origin' => '0',
            'ncm_code' => '84733049',
            'cfop_code' => '5202',
            'quantity' => 1,
            'unit_of_measure' => 'UN',
            'unit_price' => 100,
            'total_price' => 100,
            'included_in_total' => true,
            'tax_data' => [
                'imposto' => [
                    'icms' => ['situacao_tributaria' => '00'],
                    'pis' => ['situacao_tributaria' => '04'],
                    'cofins' => ['situacao_tributaria' => '04'],
                ],
            ],
            'created_by' => $user->id,
        ]);

        $action = app(BuildNfePayloadAction::class);
        $payload = $action->execute($document);

        $this->assertNull($payload);
        $this->assertSame(
            "Item 1: a empresa está no Simples Nacional e deve informar CSOSN (100-900) no ICMS, não CST '00'.",
            $action->getMessage()
        );
    }
}
