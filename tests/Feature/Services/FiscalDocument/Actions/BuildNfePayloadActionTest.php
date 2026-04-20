<?php

namespace Tests\Feature\Services\FiscalDocument\Actions;

use App\Enum\FiscalDocument\BuyerPresenceIndicator;
use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\FreightModality;
use App\Enum\FiscalDocument\IssuePurpose;
use App\Enum\FiscalDocument\OperationNature;
use App\Enum\FiscalDocument\OperationType;
use App\Enum\FiscalDocument\Status;
use App\Models\FiscalProfile;
use App\Models\Company;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentItem;
use App\Models\Partner;
use App\Models\Product;
use App\Models\User;
use App\Services\FiscalDocument\Actions\BuildNfePayloadAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
