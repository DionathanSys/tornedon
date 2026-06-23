<?php

namespace Tests\Feature\Services\FiscalDocument;

use App\Enum\FiscalDocument\BuyerPresenceIndicator;
use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\IssuePurpose;
use App\Enum\FiscalDocument\OperationNature;
use App\Enum\FiscalDocument\OperationType;
use App\Enum\FiscalDocument\Status as FiscalDocumentStatus;
use App\Enum\Invoice\Status as InvoiceStatus;
use App\Enum\Product\OriginSalePrice;
use App\Enum\Product\Unit;
use App\Enum\Requisition\Status as RequisitionStatus;
use App\Enum\Tax\TaxRegime;
use App\Enum\WarrantyClaim\CoverageType;
use App\Enum\WarrantyClaim\Responsibility;
use App\Enum\WarrantyClaim\Status as WarrantyStatus;
use App\Enum\WarrantyClaim\SupplierDecision;
use App\Enum\WarrantyClaim\SupplierResolution;
use App\Enum\WarrantyClaim\Type as WarrantyType;
use App\Models\Company;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentItem;
use App\Models\FiscalProfile;
use App\Models\FiscalRule;
use App\Models\Invoice;
use App\Models\Partner;
use App\Models\Product;
use App\Models\ProductTax;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\User;
use App\Models\WarrantyClaim;
use App\Services\FiscalDocument\WarrantyRemittanceFiscalDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WarrantyRemittanceFiscalDocumentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_generates_warranty_remittance_document_from_claim(): void
    {
        [$user, $claim, $originFiscalDocument] = $this->createScenario();

        $service = app(WarrantyRemittanceFiscalDocumentService::class);
        $document = $service->generateFromWarrantyClaim($claim, $user->id);

        $this->assertNotNull($document, $service->getMessage());
        $this->assertFalse($service->hasError());
        $this->assertSame(OperationNature::REMESSA_GARANTIA, $document->operation_nature);
        $this->assertSame(OperationType::SAIDA, $document->operation_type);
        $this->assertSame($claim->supplier_id, $document->customer_id);
        $this->assertSame($claim->id, data_get($document->tax_data, 'reference.warranty_claim_id'));
        $this->assertSame('warranty_remittance', data_get($document->tax_data, 'reference.type'));

        $item = FiscalDocumentItem::query()->where('fiscal_document_id', $document->id)->first();

        $this->assertNotNull($item);
        $this->assertSame(250.0, (float) $item->unit_price);
        $this->assertSame(250.0, (float) $item->total_price);
        $this->assertSame('5915', $item->cfop_code);
        $this->assertSame('102', data_get($item->tax_data, 'imposto.icms.situacao_tributaria'));

        $this->assertDatabaseHas('fiscal_document_item_origins', [
            'origin_fiscal_document_id' => $originFiscalDocument->id,
            'return_fiscal_document_id' => $document->id,
        ]);

        $this->assertSame($document->id, $claim->fresh()->remittance_fiscal_document_id);
    }

    public function test_it_blocks_multiple_remittance_documents_for_same_claim(): void
    {
        [$user, $claim] = $this->createScenario();

        $service = app(WarrantyRemittanceFiscalDocumentService::class);
        $first = $service->generateFromWarrantyClaim($claim, $user->id);

        $this->assertNotNull($first);

        $second = $service->generateFromWarrantyClaim($claim->fresh(), $user->id);

        $this->assertNull($second);
        $this->assertTrue($service->hasError());
        $this->assertSame('Já existe uma NF-e de remessa em garantia vinculada a esta garantia.', $service->getMessage());
    }

    private function createScenario(): array
    {
        $user = User::factory()->create();

        $company = Company::query()->create([
            'name' => 'Empresa Garantia Fiscal',
            'document_number' => '11122233000155',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'email' => 'empresa-garantia@example.com',
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        $customer = Partner::query()->create([
            'name' => 'Cliente Garantia Fiscal',
            'document_type' => 'CPF',
            'document_number' => '12345678911',
            'created_by' => $user->id,
        ]);

        $supplier = Partner::query()->create([
            'name' => 'Fornecedor Garantia Fiscal',
            'document_type' => 'CNPJ',
            'document_number' => '55443322000177',
            'created_by' => $user->id,
        ]);

        $fiscalProfile = FiscalProfile::query()->create([
            'company_id' => $company->id,
            'tax_regime' => TaxRegime::SIMPLES_NACIONAL->value,
            'cfop_rules' => [
                OperationNature::REMESSA_GARANTIA->value => [
                    'default_cfop' => '5915',
                ],
            ],
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        FiscalRule::query()->create([
            'company_id' => $company->id,
            'fiscal_profile_id' => $fiscalProfile->id,
            'operation_nature' => OperationNature::REMESSA_GARANTIA->value,
            'tax_regime' => TaxRegime::SIMPLES_NACIONAL->value,
            'cfop' => '5915',
            'csosn' => '102',
            'cst_pis' => '49',
            'cst_cofins' => '49',
            'priority' => 0,
            'is_active' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $product = Product::query()->create([
            'company_id' => $company->id,
            'created_by' => $user->id,
            'product_code' => 'PRD-GAR-NF-001',
            'name' => 'Motor de Partida',
            'unit' => Unit::UN->value,
            'origin_sale_price' => OriginSalePrice::FREE->value,
            'sale_price_value' => 250,
            'is_active' => true,
        ]);

        ProductTax::query()->create([
            'product_id' => $product->id,
            'product_origin' => '0',
            'ncm_code' => '85114000',
            'cest_code' => null,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $invoice = Invoice::query()->create([
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'invoice_number' => 'FAT-GAR-NF-001',
            'invoice_date' => now()->subDay()->toDateString(),
            'status' => InvoiceStatus::PENDING->value,
            'pending' => true,
            'confirmed' => false,
            'canceled' => false,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $requisition = Requisition::query()->create([
            'number' => 'REQ-GAR-NF-001',
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'invoice_id' => $invoice->id,
            'sale_date' => now()->subDay()->toDateString(),
            'status' => RequisitionStatus::OPEN->value,
            'stock_reserved' => false,
            'stock_consumed' => false,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'product_id' => $product->id,
            'unit_of_measure' => Unit::UN->value,
            'quantity' => 1,
            'quantity_in_base_unit' => 1,
            'conversion_factor_snapshot' => 1,
            'unit_price' => 250,
            'unit_cost' => 180,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'stock_consumed' => false,
            'commission_percentage' => 0,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $originFiscalDocument = FiscalDocument::query()->create([
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'invoice_id' => $invoice->id,
            'status' => FiscalDocumentStatus::CONFIRMED->value,
            'document_type' => DocumentModel::NFE->value,
            'issued_at' => now()->subDay()->toDateString(),
            'movement_at' => now()->subDay()->toDateString(),
            'document_number' => '9001',
            'document_series' => '1',
            'document_key' => '35260412345678000199550010000090011000009001',
            'operation_nature' => OperationNature::VENDA_DENTRO_ESTADO->value,
            'operation_type' => OperationType::SAIDA->value,
            'issue_purpose' => IssuePurpose::NORMAL->value,
            'is_final_consumer' => true,
            'buyer_presence_indicator' => BuyerPresenceIndicator::OUTROS->value,
            'pending' => false,
            'confirmed' => true,
            'canceled' => false,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        FiscalDocumentItem::query()->create([
            'fiscal_document_id' => $originFiscalDocument->id,
            'product_id' => $product->id,
            'product_code' => $product->product_code,
            'description' => $product->name,
            'item_number' => 1,
            'product_origin' => '0',
            'ncm_code' => '85114000',
            'cfop_code' => '5102',
            'quantity' => 1,
            'unit_of_measure' => Unit::UN->value,
            'unit_price' => 250,
            'total_price' => 250,
            'included_in_total' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $claim = WarrantyClaim::query()->create([
            'company_id' => $company->id,
            'number' => '00001',
            'type' => WarrantyType::PRODUCT_SUPPLIER->value,
            'status' => WarrantyStatus::APPROVED->value,
            'customer_id' => $customer->id,
            'supplier_id' => $supplier->id,
            'origin_requisition_id' => $requisition->id,
            'origin_invoice_id' => $invoice->id,
            'origin_fiscal_document_id' => $originFiscalDocument->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'coverage_type' => CoverageType::PARTS->value,
            'responsibility' => Responsibility::SUPPLIER->value,
            'customer_issue_description' => 'Peça com defeito em garantia.',
            'supplier_decision' => SupplierDecision::PENDING->value,
            'supplier_resolution' => SupplierResolution::NONE->value,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        return [$user, $claim, $originFiscalDocument];
    }
}
