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
use App\Enum\Payment\Condition as PaymentCondition;
use App\Enum\Payment\Method as PaymentMethod;
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

    public function test_it_generates_taxable_fields_in_base_unit_when_requisition_item_uses_alternative_unit(): void
    {
        $user = User::factory()->create();
        [$company, $customer, $invoice] = $this->createInvoiceContext($user);

        $product = Product::query()->create([
            'company_id' => $company->id,
            'created_by' => $user->id,
            'product_code' => 'PRD-NFE-ALT-001',
            'name' => 'Produto NF-e Alternativo',
            'unit' => Unit::JG->value,
            'origin_sale_price' => OriginSalePrice::FREE->value,
            'sale_price_value' => 100,
            'is_active' => true,
        ]);

        $product->alternativeUnitConversions()->create([
            'unit' => Unit::PC->value,
            'conversion_factor' => 0.125,
        ]);

        ProductTax::query()->create([
            'product_id' => $product->id,
            'product_origin' => Origin::NACIONAL->value,
            'ncm_code' => '84733049',
            'cest_code' => '1234567',
            'created_by' => $user->id,
        ]);

        $requisition = Requisition::query()->create([
            'number' => 'REQ-ALT-001',
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
            'unit_of_measure' => Unit::PC->value,
            'quantity' => 16,
            'unit_price' => 5,
            'discount_amount' => 0,
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
        $this->assertSame(Unit::PC->value, $item->unit_of_measure);
        $this->assertEquals(16.0, (float) $item->quantity);
        $this->assertSame(Unit::JG->value, $item->taxable_unit);
        $this->assertEquals(2.0, (float) $item->taxable_quantity);
        $this->assertEquals(40.0, (float) $item->taxable_unit_price);
    }

    public function test_it_consolidates_service_order_discounts_into_single_nfse_item(): void
    {
        $user = User::factory()->create();
        [$company, $customer, $invoice] = $this->createInvoiceContext($user);

        FiscalProfile::query()
            ->where('company_id', $company->id)
            ->update(['allow_unconditional_discount_nfse' => true]);

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

    public function test_it_uses_net_service_total_when_unconditional_discount_is_not_allowed(): void
    {
        $user = User::factory()->create();
        [$company, $customer, $invoice] = $this->createInvoiceContext($user);

        $serviceOrder = ServiceOrder::query()->create([
            'number' => 'SO-003',
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
            'service_code' => 'SRV-003',
            'name' => 'Servico sem desconto incondicionado',
            'price' => 120,
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
            'unit_price' => 120,
            'discount_amount' => 20,
            'created_by' => $user->id,
        ]);

        $service = app(InvoiceService::class);
        $document = $service->createFiscalDocument($invoice->fresh(), [
            'document_type' => DocumentModel::NFSE->value,
            'nfse_model' => NfseModel::MUNICIPAL->value,
            'issued_at' => now()->toDateString(),
            'nfse_item_description' => 'Descricao consolidada liquida',
        ], $user->id);

        $this->assertNotNull($document, $service->getMessage());

        $item = $document->items()->first();

        $this->assertNotNull($item);
        $this->assertSame(100.0, (float) $item->total_price);
        $this->assertSame(0.0, (float) $item->discount_amount);
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

    public function test_nfse_generation_allows_custom_description_and_additional_information(): void
    {
        $user = User::factory()->create();
        [$company, $customer, $invoice] = $this->createInvoiceContext($user);

        $serviceOrder = ServiceOrder::query()->create([
            'number' => 'SO-NAME-001',
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
            'service_code' => 'SRV-NAME-001',
            'name' => 'Manutenção preventiva',
            'price' => 200,
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
            'unit_price' => 200,
            'created_by' => $user->id,
        ]);

        $service = app(InvoiceService::class);
        $document = $service->createFiscalDocument($invoice->fresh(), [
            'document_type' => DocumentModel::NFSE->value,
            'nfse_model' => NfseModel::MUNICIPAL->value,
            'issued_at' => now()->toDateString(),
            'nfse_item_description' => 'Descrição ajustada pelo usuário',
            'nfse_additional_information' => 'Informação adicional ajustada',
        ], $user->id);

        $this->assertNotNull($document, $service->getMessage());

        $item = $document->items()->first();

        $this->assertNotNull($item);
        $this->assertSame($serviceModel->id, $item->service_id);
        $this->assertSame('Descrição ajustada pelo usuário', $item->description);
        $this->assertSame('Informação adicional ajustada', $item->additional_information);
    }

    public function test_nfse_generation_requires_service_choice_when_invoice_has_multiple_services(): void
    {
        $user = User::factory()->create();
        [$company, $customer, $invoice] = $this->createInvoiceContext($user);

        $serviceOrder = ServiceOrder::query()->create([
            'number' => 'SO-MULTI-001',
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'invoice_id' => $invoice->id,
            'order_date' => now()->toDateString(),
            'status' => ServiceOrderState::CLOSED->value,
            'priority' => ServiceOrderPriority::NORMAL->value,
            'type' => ServiceOrderType::MAINTENANCE->value,
            'created_by' => $user->id,
        ]);

        $firstService = Service::query()->create([
            'company_id' => $company->id,
            'created_by' => $user->id,
            'service_code' => 'SRV-MULTI-001',
            'name' => 'Instalação',
            'price' => 100,
            'tax_rate' => 3,
            'nbs_code' => '111111111',
            'cnae_code' => '6201500',
            'municipal_tax_code' => '01.01',
            'is_active' => true,
        ]);

        $secondService = Service::query()->create([
            'company_id' => $company->id,
            'created_by' => $user->id,
            'service_code' => 'SRV-MULTI-002',
            'name' => 'Treinamento operacional',
            'price' => 150,
            'tax_rate' => 4,
            'nbs_code' => '222222222',
            'cnae_code' => '8599604',
            'municipal_tax_code' => '08.02',
            'is_active' => true,
        ]);

        foreach ([$firstService, $secondService] as $serviceModel) {
            ServiceOrderItem::query()->create([
                'service_order_id' => $serviceOrder->id,
                'service_id' => $serviceModel->id,
                'quantity' => 1,
                'unit_price' => (float) $serviceModel->price,
                'created_by' => $user->id,
            ]);
        }

        $service = app(InvoiceService::class);
        $document = $service->createFiscalDocument($invoice->fresh(), [
            'document_type' => DocumentModel::NFSE->value,
            'nfse_model' => NfseModel::MUNICIPAL->value,
            'issued_at' => now()->toDateString(),
        ], $user->id);

        $this->assertNull($document);
        $this->assertSame(
            'A fatura possui mais de um serviço nas ordens de serviço. Selecione qual serviço deve ser usado na descrição do item da NFS-e.',
            $service->getMessage()
        );
    }

    public function test_nfse_generation_uses_selected_service_for_description_and_classification(): void
    {
        $user = User::factory()->create();
        [$company, $customer, $invoice] = $this->createInvoiceContext($user);

        $serviceOrder = ServiceOrder::query()->create([
            'number' => 'SO-SELECT-001',
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'invoice_id' => $invoice->id,
            'order_date' => now()->toDateString(),
            'status' => ServiceOrderState::CLOSED->value,
            'priority' => ServiceOrderPriority::NORMAL->value,
            'type' => ServiceOrderType::MAINTENANCE->value,
            'created_by' => $user->id,
        ]);

        $firstService = Service::query()->create([
            'company_id' => $company->id,
            'created_by' => $user->id,
            'service_code' => 'SRV-SELECT-001',
            'name' => 'Diagnóstico técnico',
            'price' => 100,
            'tax_rate' => 3,
            'nbs_code' => '111111111',
            'cnae_code' => '6201500',
            'municipal_tax_code' => '01.01',
            'is_active' => true,
        ]);

        $secondService = Service::query()->create([
            'company_id' => $company->id,
            'created_by' => $user->id,
            'service_code' => 'SRV-SELECT-002',
            'name' => 'Comissionamento',
            'price' => 150,
            'tax_rate' => 4,
            'nbs_code' => '222222222',
            'cnae_code' => '8599604',
            'municipal_tax_code' => '08.02',
            'is_active' => true,
        ]);

        foreach ([$firstService, $secondService] as $serviceModel) {
            ServiceOrderItem::query()->create([
                'service_order_id' => $serviceOrder->id,
                'service_id' => $serviceModel->id,
                'quantity' => 1,
                'unit_price' => (float) $serviceModel->price,
                'created_by' => $user->id,
            ]);
        }

        $service = app(InvoiceService::class);
        $document = $service->createFiscalDocument($invoice->fresh(), [
            'document_type' => DocumentModel::NFSE->value,
            'nfse_model' => NfseModel::MUNICIPAL->value,
            'issued_at' => now()->toDateString(),
            'nfse_service_id' => $secondService->id,
            'nfse_item_description' => 'Descrição de comissionamento ajustada',
            'nfse_additional_information' => 'OS #SO-SELECT-001 validada pelo usuário',
        ], $user->id);

        $this->assertNotNull($document, $service->getMessage());

        $item = $document->items()->first();

        $this->assertNotNull($item);
        $this->assertSame($secondService->id, $item->service_id);
        $this->assertSame('Descrição de comissionamento ajustada', $item->description);
        $this->assertSame('OS #SO-SELECT-001 validada pelo usuário', $item->additional_information);
        $this->assertSame('08.02', $item->municipal_tax_code);
        $this->assertSame('222222222', $item->nbs_code);
        $this->assertSame('8599604', $item->cnae_code);
        $this->assertSame(4.0, (float) $item->iss_rate);
    }

    public function test_confirm_invoice_uses_nfse_description_and_additional_information_from_confirmation_data(): void
    {
        $user = User::factory()->create();
        [$company, $customer, $invoice] = $this->createInvoiceContext($user);

        $serviceOrder = ServiceOrder::query()->create([
            'number' => 'SO-CONFIRM-001',
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
            'service_code' => 'SRV-CONFIRM-001',
            'name' => 'Serviço de confirmação',
            'price' => 180,
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
            'unit_price' => 180,
            'created_by' => $user->id,
        ]);

        $service = app(InvoiceService::class);
        $result = $service->confirm($invoice->fresh(), [
            'payment_method' => PaymentMethod::PIX->value,
            'payment_condition' => PaymentCondition::CASH->value,
            'nfse_item_description' => 'Descrição definida na confirmação',
            'nfse_additional_information' => 'Informação adicional definida na confirmação',
        ], $user->id);

        $this->assertNotNull($result, $service->getMessage());

        $document = $invoice->fresh()->fiscalDocuments()->where('document_type', DocumentModel::NFSE->value)->first();
        $item = $document?->items()->first();

        $this->assertNotNull($item);
        $this->assertSame('Descrição definida na confirmação', $item->description);
        $this->assertSame('Informação adicional definida na confirmação', $item->additional_information);
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
