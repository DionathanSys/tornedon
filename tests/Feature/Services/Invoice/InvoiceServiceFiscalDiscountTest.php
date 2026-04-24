<?php

namespace Tests\Feature\Services\Invoice;

use App\Enum\FiscalDocument\BuyerPresenceIndicator;
use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\FreightModality;
use App\Enum\FiscalDocument\IssuePurpose;
use App\Enum\FiscalDocument\NfseModel;
use App\Enum\FiscalDocument\OperationNature;
use App\Enum\FiscalDocument\OperationType;
use App\Enum\Invoice\Status as InvoiceStatus;
use App\Enum\Product\Origin;
use App\Enum\Product\OriginSalePrice;
use App\Enum\Product\Unit;
use App\Enum\Requisition\Status as RequisitionStatus;
use App\Enum\ServiceOrder\Priority as ServiceOrderPriority;
use App\Enum\ServiceOrder\State as ServiceOrderState;
use App\Enum\ServiceOrder\Type as ServiceOrderType;
use App\Enum\Tax\TaxRegime;
use App\Models\Company;
use App\Models\FiscalProfile;
use App\Models\Invoice;
use App\Models\Partner;
use App\Models\Product;
use App\Models\ProductTax;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderItem;
use App\Models\User;
use App\Services\Invoice\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceServiceFiscalDiscountTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_copies_requisition_item_discount_to_nfe_items(): void
    {
        $user = User::factory()->create();
        [$company, $customer, $invoice] = $this->createInvoiceContext($user);

        $product = Product::query()->create([
            'company_id' => $company->id,
            'created_by' => $user->id,
            'product_code' => 'PRD-NFE-001',
            'name' => 'Produto NF-e',
            'unit' => Unit::UN->value,
            'origin_sale_price' => OriginSalePrice::FREE->value,
            'sale_price_value' => 100,
            'is_active' => true,
        ]);

        ProductTax::query()->create([
            'product_id' => $product->id,
            'product_origin' => Origin::NACIONAL->value,
            'ncm_code' => '84733049',
            'cest_code' => '1234567',
            'created_by' => $user->id,
        ]);

        $requisition = Requisition::query()->create([
            'number' => 'REQ-001',
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'invoice_id' => $invoice->id,
            'sale_date' => now()->toDateString(),
            'status' => RequisitionStatus::OPEN->value,
            'stock_consumed' => false,
            'created_by' => $user->id,
        ]);

        RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'product_id' => $product->id,
            'unit_of_measure' => Unit::UN->value,
            'quantity' => 2,
            'unit_price' => 50,
            'discount_amount' => 15,
            'created_by' => $user->id,
        ]);

        $service = app(InvoiceService::class);
        $document = $service->createFiscalDocument($invoice->fresh(), [
            'document_type' => DocumentModel::NFE->value,
            'operation_nature' => OperationNature::VENDA_DENTRO_ESTADO->value,
            'operation_type' => OperationType::SAIDA->value,
            'issue_purpose' => IssuePurpose::NORMAL->value,
            'is_final_consumer' => true,
            'buyer_presence_indicator' => BuyerPresenceIndicator::OUTROS->value,
            'freight_data' => [
                'modalidade_frete' => FreightModality::SEM_FRETE->value,
            ],
            'issued_at' => now()->toDateString(),
            'movement_at' => now()->toDateString(),
        ], $user->id);

        $this->assertNotNull($document, $service->getMessage());

        $item = $document->items()->first();

        $this->assertNotNull($item);
        $this->assertSame(100.0, (float) $item->total_price);
        $this->assertSame(15.0, (float) $item->discount_amount);
    }

    public function test_it_consolidates_service_order_discounts_into_single_nfse_item(): void
    {
        $user = User::factory()->create();
        [$company, $customer, $invoice] = $this->createInvoiceContext($user);

        $serviceOrder = ServiceOrder::query()->create([
            'number' => 'SO-001',
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'invoice_id' => $invoice->id,
            'order_date' => now()->toDateString(),
            'status' => ServiceOrderState::CLOSED->value,
            'priority' => ServiceOrderPriority::NORMAL->value,
            'type' => ServiceOrderType::MAINTENANCE->value,
            'created_by' => $user->id,
        ]);

        $serviceModel = Service::query()->create([
            'company_id' => $company->id,
            'created_by' => $user->id,
            'service_code' => 'SRV-001',
            'name' => 'Servico Municipal',
            'price' => 100,
            'tax_rate' => 5,
            'nbs_code' => '123456789',
            'cnae_code' => '6201500',
            'municipal_tax_code' => '01.01',
            'is_active' => true,
        ]);

        ServiceOrderItem::query()->create([
            'service_order_id' => $serviceOrder->id,
            'service_id' => $serviceModel->id,
            'quantity' => 1,
            'unit_price' => 100,
            'discount_amount' => 10,
            'created_by' => $user->id,
        ]);

        ServiceOrderItem::query()->create([
            'service_order_id' => $serviceOrder->id,
            'service_id' => $serviceModel->id,
            'quantity' => 1,
            'unit_price' => 50,
            'discount_amount' => 5,
            'created_by' => $user->id,
        ]);

        $service = app(InvoiceService::class);
        $document = $service->createFiscalDocument($invoice->fresh(), [
            'document_type' => DocumentModel::NFSE->value,
            'nfse_model' => NfseModel::MUNICIPAL->value,
            'issued_at' => now()->toDateString(),
            'nfse_item_description' => 'Descricao consolidada',
        ], $user->id);

        $this->assertNotNull($document, $service->getMessage());

        $item = $document->items()->first();

        $this->assertNotNull($item);
        $this->assertSame(150.0, (float) $item->total_price);
        $this->assertSame(15.0, (float) $item->discount_amount);
    }

    public function test_invoice_totals_do_not_apply_service_order_discount_twice(): void
    {
        $user = User::factory()->create();
        [$company, $customer, $invoice] = $this->createInvoiceContext($user);

        $serviceOrder = ServiceOrder::query()->create([
            'number' => 'SO-002',
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'invoice_id' => $invoice->id,
            'order_date' => now()->toDateString(),
            'status' => ServiceOrderState::CLOSED->value,
            'priority' => ServiceOrderPriority::NORMAL->value,
            'type' => ServiceOrderType::MAINTENANCE->value,
            'created_by' => $user->id,
        ]);

        $serviceModel = Service::query()->create([
            'company_id' => $company->id,
            'created_by' => $user->id,
            'service_code' => 'SRV-002',
            'name' => 'Servico com desconto',
            'price' => 75,
            'tax_rate' => 5,
            'nbs_code' => '123456789',
            'cnae_code' => '6201500',
            'municipal_tax_code' => '01.01',
            'is_active' => true,
        ]);

        ServiceOrderItem::query()->create([
            'service_order_id' => $serviceOrder->id,
            'service_id' => $serviceModel->id,
            'quantity' => 1,
            'unit_price' => 75,
            'discount_amount' => 7.5,
            'created_by' => $user->id,
        ]);

        $invoice->refresh();

        $this->assertSame(67.5, (float) $invoice->services_amount);
        $this->assertSame(67.5, (float) $invoice->total_amount);
        $this->assertSame(7.5, (float) $invoice->discount_amount);
        $this->assertSame(67.5, (float) $invoice->net_value);
    }

    /**
     * @return array{Company, Partner, Invoice}
     */
    private function createInvoiceContext(User $user): array
    {
        $company = Company::query()->create([
            'name' => 'Empresa Invoice',
            'document_number' => '12345678000199',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);

        FiscalProfile::query()->create([
            'company_id' => $company->id,
            'tax_regime' => TaxRegime::LUCRO_PRESUMIDO->value,
            'cfop_rules' => [
                OperationNature::VENDA_DENTRO_ESTADO->value => [
                    'default_cfop' => '5102',
                    'exceptions' => [],
                ],
            ],
            'iss_rate_default' => 5,
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        $customer = Partner::query()->create([
            'name' => 'Cliente Invoice',
            'document_type' => 'CNPJ',
            'document_number' => '22345678000188',
            'created_by' => $user->id,
        ]);

        $invoice = Invoice::query()->create([
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'invoice_number' => 'INV-001',
            'invoice_date' => now()->toDateString(),
            'status' => InvoiceStatus::PENDING->value,
            'pending' => true,
            'confirmed' => false,
            'canceled' => false,
            'created_by' => $user->id,
        ]);

        return [$company, $customer, $invoice];
    }
}
