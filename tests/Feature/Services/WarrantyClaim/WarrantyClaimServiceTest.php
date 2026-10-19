<?php

namespace Tests\Feature\Services\WarrantyClaim;

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
use App\Enum\ServiceOrder\Priority;
use App\Enum\ServiceOrder\State as ServiceOrderState;
use App\Enum\ServiceOrder\Type as ServiceOrderType;
use App\Enum\WarrantyClaim\CoverageType;
use App\Enum\WarrantyClaim\Responsibility;
use App\Enum\WarrantyClaim\Type;
use App\Filament\Clusters\Sales\Resources\WarrantyClaims\Pages\CreateWarrantyClaim;
use App\Models\Company;
use App\Models\Equipment;
use App\Models\FiscalDocument;
use App\Models\Invoice;
use App\Models\Partner;
use App\Models\Product;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\WarrantyClaim\WarrantyClaimService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WarrantyClaimServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_opens_warranty_from_service_order(): void
    {
        [$user, $serviceOrder] = $this->makeServiceOrderScenario();

        $service = app(WarrantyClaimService::class);
        $claim = $service->openFromServiceOrder($serviceOrder, [
            'customer_issue_description' => 'Cliente relata falha recorrente no serviço executado.',
        ], $user->id);

        $this->assertNotNull($claim, $service->getMessage());
        $this->assertFalse($service->hasError());
        $this->assertSame(Type::SERVICE_COMPANY, $claim->type);
        $this->assertSame($serviceOrder->id, $claim->origin_service_order_id);
        $this->assertSame($serviceOrder->customer_id, $claim->customer_id);
        $this->assertSame((string) $serviceOrder->warranty_expires_at?->toDateString(), (string) $claim->expires_at?->toDateString());
    }

    public function test_it_blocks_service_warranty_without_origin_service_order(): void
    {
        [$user, $serviceOrder] = $this->makeServiceOrderScenario();

        $service = app(WarrantyClaimService::class);
        $claim = $service->create([
            'company_id' => $serviceOrder->company_id,
            'type' => Type::SERVICE_COMPANY->value,
            'status' => 'draft',
            'customer_id' => $serviceOrder->customer_id,
            'quantity' => 1,
            'coverage_type' => CoverageType::LABOR->value,
            'responsibility' => Responsibility::COMPANY->value,
            'customer_issue_description' => 'Sem origem vinculada.',
            'supplier_decision' => 'pending',
            'supplier_resolution' => 'none',
        ], $user->id);

        $this->assertNull($claim);
        $this->assertTrue($service->hasError());
        $this->assertArrayHasKey('origin_service_order_id', $service->getErrors());
    }

    public function test_it_opens_warranty_from_requisition_and_keeps_sale_traceability(): void
    {
        [$user, $requisition, $supplier, $product, $invoice, $fiscalDocument] = $this->makeRequisitionScenario();

        $service = app(WarrantyClaimService::class);
        $claim = $service->openFromRequisition($requisition, [
            'product_id' => $product->id,
            'supplier_id' => $supplier->id,
            'customer_issue_description' => 'Peça apresentou defeito após a instalação.',
            'advanced_replacement' => true,
        ], $user->id);

        $this->assertNotNull($claim, $service->getMessage());
        $this->assertFalse($service->hasError());
        $this->assertSame(Type::PRODUCT_SUPPLIER, $claim->type);
        $this->assertSame($supplier->id, $claim->supplier_id);
        $this->assertSame($requisition->id, $claim->origin_requisition_id);
        $this->assertSame($invoice->id, $claim->origin_invoice_id);
        $this->assertSame($fiscalDocument->id, $claim->origin_fiscal_document_id);
        $this->assertTrue($claim->advanced_replacement);
        $this->assertSame((string) $product->id, (string) $claim->product_id);
    }

    public function test_it_blocks_product_supplier_warranty_without_supplier(): void
    {
        [$user, $requisition, $supplier, $product] = $this->makeRequisitionScenario();

        $service = app(WarrantyClaimService::class);
        $claim = $service->openFromRequisition($requisition, [
            'product_id' => $product->id,
            'customer_issue_description' => 'Fornecedor ausente.',
        ], $user->id);

        $this->assertNull($claim);
        $this->assertTrue($service->hasError());
        $this->assertArrayHasKey('supplier_id', $service->getErrors());
    }

    public function test_it_creates_and_updates_supplier_warranty_from_purchase_without_customer(): void
    {
        [$user, $requisition, $supplier, $product, , $purchase] = $this->makeRequisitionScenario();
        $purchase->update([
            'operation_type' => OperationType::ENTRADA->value,
            'customer_id' => $supplier->id,
            'invoice_id' => null,
        ]);

        $service = app(WarrantyClaimService::class);
        $claim = $service->create([
            'company_id' => $requisition->company_id,
            'type' => Type::PRODUCT_SUPPLIER->value,
            'status' => 'draft',
            'supplier_id' => $supplier->id,
            'product_id' => $product->id,
            'origin_fiscal_document_id' => $purchase->id,
            'quantity' => 1,
            'coverage_type' => CoverageType::LABOR_AND_PARTS->value,
            'responsibility' => Responsibility::COMPANY->value,
            'customer_issue_description' => 'Peça do estoque com defeito.',
            'supplier_decision' => 'pending',
            'supplier_resolution' => 'none',
        ], $user->id);

        $this->assertNotNull($claim, json_encode($service->getErrors()));
        $this->assertNull($claim->fresh()->customer_id);

        $updated = $service->update($claim, ['supplier_protocol' => 'RMA-123'], $user->id);

        $this->assertNotNull($updated, json_encode($service->getErrors()));
        $this->assertNull($updated->customer_id);
        $this->assertSame('RMA-123', $updated->supplier_protocol);

        // O parceiro de uma nota de entrada é o fornecedor, não o cliente da garantia.
        $updated = $service->update($updated, ['customer_id' => $requisition->customer_id], $user->id);

        $this->assertNotNull($updated, json_encode($service->getErrors()));
        $this->assertSame($requisition->customer_id, $updated->customer_id);
    }

    public function test_supplier_warranty_form_accepts_purchase_without_customer(): void
    {
        [$user, $requisition, $supplier, $product, , $purchase] = $this->makeRequisitionScenario();
        $purchase->update([
            'operation_type' => OperationType::ENTRADA->value,
            'customer_id' => $supplier->id,
            'invoice_id' => null,
        ]);
        $company = Company::query()->findOrFail($requisition->company_id);
        $user->companies()->attach($company, ['role' => 'admin', 'is_active' => true]);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($company);

        Livewire::test(CreateWarrantyClaim::class)
            ->fillForm([
                'type' => Type::PRODUCT_SUPPLIER->value,
                'customer_id' => null,
                'supplier_id' => $supplier->id,
                'product_id' => $product->id,
                'origin_fiscal_document_id' => $purchase->id,
                'quantity' => 1,
                'customer_issue_description' => 'Peça do estoque com defeito.',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('warranty_claims', [
            'company_id' => $company->id,
            'customer_id' => null,
            'supplier_id' => $supplier->id,
            'product_id' => $product->id,
            'origin_fiscal_document_id' => $purchase->id,
        ]);
    }

    public function test_it_still_requires_customer_for_service_warranty(): void
    {
        [$user, $serviceOrder] = $this->makeServiceOrderScenario();

        $service = app(WarrantyClaimService::class);
        $claim = $service->openFromServiceOrder($serviceOrder, [
            'customer_id' => null,
            'customer_issue_description' => 'Falha no serviço.',
        ], $user->id);

        $this->assertNull($claim);
        $this->assertArrayHasKey('customer_id', $service->getErrors());
    }

    public function test_it_preserves_company_validation_for_purchase_origin_without_customer(): void
    {
        [$user, $requisition, $supplier, $product, , $purchase] = $this->makeRequisitionScenario();
        $otherCompany = Company::query()->create([
            'name' => 'Outra empresa',
            'document_number' => '30303030000173',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);
        $purchase->update(['company_id' => $otherCompany->id, 'operation_type' => OperationType::ENTRADA->value]);

        $service = app(WarrantyClaimService::class);
        $claim = $service->openFromRequisition($requisition, [
            'customer_id' => null,
            'supplier_id' => $supplier->id,
            'product_id' => $product->id,
            'origin_fiscal_document_id' => $purchase->id,
            'customer_issue_description' => 'Peça com defeito.',
        ], $user->id);

        $this->assertNull($claim);
        $this->assertArrayHasKey('origin_fiscal_document_id', $service->getErrors());
    }

    private function makeServiceOrderScenario(): array
    {
        $user = User::factory()->create();

        $company = Company::query()->create([
            'name' => 'Empresa Garantia Servico',
            'document_number' => '10101010000191',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);

        $customer = Partner::query()->create([
            'name' => 'Cliente Servico',
            'document_type' => 'CPF',
            'document_number' => '12312312312',
            'created_by' => $user->id,
        ]);

        $equipment = Equipment::query()->create([
            'company_id' => $company->id,
            'owner_id' => $customer->id,
            'name' => 'Equipamento Teste',
            'type' => 'other',
            'serial_number' => 'EQ-001',
            'created_by' => $user->id,
        ]);

        $serviceOrder = ServiceOrder::query()->create([
            'number' => 'OS-GAR-001',
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'equipment_id' => $equipment->id,
            'order_date' => now()->subDays(10)->toDateString(),
            'warranty_expires_at' => now()->addDays(30)->toDateString(),
            'status' => ServiceOrderState::OPEN->value,
            'priority' => Priority::NORMAL->value,
            'type' => ServiceOrderType::MAINTENANCE->value,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        return [$user, $serviceOrder];
    }

    private function makeRequisitionScenario(): array
    {
        $user = User::factory()->create();

        $company = Company::query()->create([
            'name' => 'Empresa Garantia Peca',
            'document_number' => '20202020000182',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);

        $customer = Partner::query()->create([
            'name' => 'Cliente Peca',
            'document_type' => 'CPF',
            'document_number' => '32132132132',
            'created_by' => $user->id,
        ]);

        $supplier = Partner::query()->create([
            'name' => 'Fornecedor Peca',
            'document_type' => 'CNPJ',
            'document_number' => '99888777000155',
            'created_by' => $user->id,
        ]);

        $product = Product::query()->create([
            'company_id' => $company->id,
            'created_by' => $user->id,
            'product_code' => 'PRD-GAR-001',
            'name' => 'Bomba Hidraulica',
            'unit' => Unit::UN->value,
            'origin_sale_price' => OriginSalePrice::FREE->value,
            'sale_price_value' => 250,
            'is_active' => true,
        ]);

        $serviceOrder = ServiceOrder::query()->create([
            'number' => 'OS-GAR-REQ-001',
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'order_date' => now()->subDays(5)->toDateString(),
            'status' => ServiceOrderState::OPEN->value,
            'priority' => Priority::NORMAL->value,
            'type' => ServiceOrderType::MAINTENANCE->value,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $invoice = Invoice::query()->create([
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'invoice_number' => 'FAT-GAR-001',
            'invoice_date' => now()->subDays(4)->toDateString(),
            'status' => InvoiceStatus::PENDING->value,
            'pending' => true,
            'confirmed' => false,
            'canceled' => false,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $requisition = Requisition::query()->create([
            'number' => 'REQ-GAR-001',
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'service_order_id' => $serviceOrder->id,
            'invoice_id' => $invoice->id,
            'sale_date' => now()->subDays(4)->toDateString(),
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

        $fiscalDocument = FiscalDocument::query()->create([
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'invoice_id' => $invoice->id,
            'status' => FiscalDocumentStatus::CONFIRMED->value,
            'document_type' => DocumentModel::NFE->value,
            'issued_at' => now()->subDays(3)->toDateString(),
            'movement_at' => now()->subDays(3)->toDateString(),
            'document_number' => '12345',
            'document_series' => '1',
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

        return [$user, $requisition, $supplier, $product, $invoice, $fiscalDocument];
    }
}
