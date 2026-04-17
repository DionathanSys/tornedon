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
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentItem;
use App\Models\FiscalDocumentItemOrigin;
use App\Models\FiscalProfile;
use App\Models\Partner;
use App\Models\Product;
use App\Models\User;
use App\Services\FiscalDocument\PurchaseReturnFiscalDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseReturnFiscalDocumentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_generates_a_purchase_return_draft_from_entry_note(): void
    {
        [$user, $company, $supplier] = $this->createBaseContext();
        $originDocument = $this->createEntryDocument($company, $supplier, $user);
        $originItem = $this->createEntryItem($originDocument, $company, $user);

        $service = app(PurchaseReturnFiscalDocumentService::class);
        $returnDocument = $service->generateFromEntry($originDocument, $user->id);

        $this->assertNotNull($returnDocument, $service->getMessage());
        $this->assertSame(DocumentModel::NFE, $returnDocument->document_type);
        $this->assertSame(OperationType::SAIDA, $returnDocument->operation_type);
        $this->assertSame(IssuePurpose::DEVOLUCAO, $returnDocument->issue_purpose);
        $this->assertSame(OperationNature::DEVOLUCAO_COMPRA, $returnDocument->operation_nature);
        $this->assertSame($supplier->id, $returnDocument->customer_id);
        $this->assertSame($originDocument->id, data_get($returnDocument->tax_data, 'purchase_return_origin.fiscal_document_id'));

        $returnItems = FiscalDocumentItem::query()
            ->where('fiscal_document_id', $returnDocument->id)
            ->orderBy('item_number')
            ->get();

        $this->assertCount(1, $returnItems);
        $this->assertSame($originItem->product_id, $returnItems[0]->product_id);
        $this->assertSame($originItem->description, $returnItems[0]->description);
        $this->assertSame((float) $originItem->quantity, (float) $returnItems[0]->quantity);
        $this->assertSame((float) $originItem->unit_price, (float) $returnItems[0]->unit_price);
        $this->assertSame((float) $originItem->total_price, (float) $returnItems[0]->total_price);
        $this->assertSame($originItem->ncm_code, $returnItems[0]->ncm_code);
        $this->assertSame($originItem->cest_code, $returnItems[0]->cest_code);

        $link = FiscalDocumentItemOrigin::query()->first();

        $this->assertNotNull($link);
        $this->assertSame($originDocument->id, $link->origin_fiscal_document_id);
        $this->assertSame($originItem->id, $link->origin_fiscal_document_item_id);
        $this->assertSame($returnDocument->id, $link->return_fiscal_document_id);
        $this->assertSame($returnItems[0]->id, $link->return_fiscal_document_item_id);
        $this->assertSame((float) $originItem->quantity, (float) $link->linked_quantity);
        $this->assertSame((float) $originItem->total_price, (float) $link->linked_value);
        $this->assertSame($originDocument->document_key, $link->origin_document_key);
    }

    public function test_it_blocks_generation_when_origin_document_has_no_items(): void
    {
        [$user, $company, $supplier] = $this->createBaseContext();
        $originDocument = $this->createEntryDocument($company, $supplier, $user);

        $service = app(PurchaseReturnFiscalDocumentService::class);
        $returnDocument = $service->generateFromEntry($originDocument, $user->id);

        $this->assertNull($returnDocument);
        $this->assertSame('A nota de entrada não possui itens para gerar devolução.', $service->getMessage());
    }

    private function createBaseContext(): array
    {
        $user = User::factory()->create();

        $company = Company::query()->create([
            'name' => 'Empresa Devolução',
            'document_number' => '12345678000199',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);

        $supplier = Partner::query()->create([
            'name' => 'Fornecedor Origem',
            'document_type' => 'CNPJ',
            'document_number' => '22345678000188',
            'created_by' => $user->id,
        ]);

        FiscalProfile::query()->create([
            'company_id' => $company->id,
            'tax_regime' => 'simples_nacional',
            'cfop_rules' => [
                OperationNature::DEVOLUCAO_COMPRA->value => [
                    'default_cfop' => '5202',
                ],
                OperationNature::VENDA_DENTRO_ESTADO->value => [
                    'default_cfop' => '5102',
                ],
            ],
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        return [$user, $company, $supplier];
    }

    private function createEntryDocument(Company $company, Partner $supplier, User $user): FiscalDocument
    {
        return FiscalDocument::query()->create([
            'customer_id' => $supplier->id,
            'company_id' => $company->id,
            'status' => Status::CONFIRMED->value,
            'document_type' => DocumentModel::NFE->value,
            'issued_at' => now()->subDay()->toDateString(),
            'movement_at' => now()->subDay()->toDateString(),
            'document_number' => '12345',
            'document_series' => '1',
            'document_key' => '35260412345678000199550010000123451000012345',
            'operation_nature' => OperationNature::DEVOLUCAO_COMPRA->value,
            'operation_type' => OperationType::ENTRADA->value,
            'issue_purpose' => IssuePurpose::NORMAL->value,
            'is_final_consumer' => false,
            'buyer_presence_indicator' => BuyerPresenceIndicator::OUTROS->value,
            'freight_data' => [
                'modalidade_frete' => FreightModality::SEM_FRETE->value,
            ],
            'pending' => false,
            'confirmed' => true,
            'canceled' => false,
            'created_by' => $user->id,
            'confirmed_by' => $user->id,
            'confirmed_at' => now()->subDay(),
        ]);
    }

    private function createEntryItem(FiscalDocument $document, Company $company, User $user): FiscalDocumentItem
    {
        $product = Product::query()->create([
            'company_id' => $company->id,
            'created_by' => $user->id,
            'product_code' => 'PRD-RET-001',
            'name' => 'Produto devolvido',
            'unit' => \App\Enum\Product\Unit::UN->value,
            'origin_sale_price' => \App\Enum\Product\OriginSalePrice::FREE->value,
            'sale_price_value' => 50,
            'is_active' => true,
        ]);

        return FiscalDocumentItem::query()->create([
            'fiscal_document_id' => $document->id,
            'product_id' => $product->id,
            'product_code' => $product->product_code,
            'description' => $product->name,
            'item_number' => 1,
            'product_origin' => '0',
            'ncm_code' => '84733049',
            'cest_code' => '1234567',
            'barcode' => '7891234567890',
            'cfop_code' => '1202',
            'quantity' => 2,
            'unit_of_measure' => 'UN',
            'taxable_unit' => 'UN',
            'taxable_quantity' => 2,
            'taxable_unit_price' => 50,
            'unit_price' => 50,
            'total_price' => 100,
            'included_in_total' => true,
            'tax_data' => [
                'imposto' => [
                    'icms' => ['situacao_tributaria' => '00'],
                    'pis' => ['situacao_tributaria' => '01'],
                    'cofins' => ['situacao_tributaria' => '01'],
                ],
            ],
            'fiscal_snapshot' => [
                'source' => 'entry_note',
            ],
            'created_by' => $user->id,
        ]);
    }
}
