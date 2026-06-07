<?php

namespace Tests\Feature\Services\Invoice;

use App\Enum\Invoice\Status as InvoiceStatus;
use App\Enum\Payment\Condition as PaymentCondition;
use App\Enum\Payment\Method as PaymentMethod;
use App\Enum\ServiceOrder\Priority as ServiceOrderPriority;
use App\Enum\ServiceOrder\State as ServiceOrderState;
use App\Enum\ServiceOrder\Type as ServiceOrderType;
use App\Models\CardPaymentProfile;
use App\Models\Company;
use App\Models\CompanyPreference;
use App\Models\FinancialCategory;
use App\Models\Invoice;
use App\Models\Partner;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderItem;
use App\Models\User;
use App\Services\Invoice\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceCardReceivableDueDateTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_generates_card_receivable_using_operator_term_without_payment_condition(): void
    {
        $user = User::factory()->create();
        $company = Company::query()->create([
            'name' => 'Empresa Cartao Invoice',
            'document_number' => '12345678000199',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);
        $customer = Partner::query()->create([
            'name' => 'Cliente Cartao Invoice',
            'document_type' => 'CPF',
            'document_number' => '12345678901',
            'created_by' => $user->id,
        ]);
        $invoice = Invoice::query()->create([
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'invoice_number' => 'INV-CARD-001',
            'invoice_date' => '2026-05-04',
            'status' => InvoiceStatus::CONFIRMED->value,
            'pending' => false,
            'confirmed' => true,
            'canceled' => false,
            'created_by' => $user->id,
        ]);

        $serviceModel = Service::query()->create([
            'company_id' => $company->id,
            'created_by' => $user->id,
            'service_code' => 'SRV-CARD-001',
            'name' => 'Servico Cartao',
            'price' => 150,
            'tax_rate' => 5,
            'nbs_code' => '123456789',
            'cnae_code' => '6201500',
            'municipal_tax_code' => '01.01',
            'is_active' => true,
        ]);

        $serviceOrder = ServiceOrder::query()->create([
            'number' => 'SO-CARD-001',
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'invoice_id' => $invoice->id,
            'order_date' => '2026-05-04',
            'status' => ServiceOrderState::CLOSED->value,
            'priority' => ServiceOrderPriority::NORMAL->value,
            'type' => ServiceOrderType::MAINTENANCE->value,
            'created_by' => $user->id,
        ]);

        ServiceOrderItem::query()->create([
            'service_order_id' => $serviceOrder->id,
            'service_id' => $serviceModel->id,
            'quantity' => 1,
            'unit_price' => 150,
            'discount_amount' => 0,
            'created_by' => $user->id,
        ]);

        $profile = CardPaymentProfile::query()->create([
            'company_id' => $company->id,
            'name' => 'Visa D+31',
            'brand' => 'Visa',
            'acquirer' => 'Stone',
            'fee_percent' => 2.99,
            'fee_fixed' => 0,
            'settlement_days' => 31,
            'active' => true,
        ]);

        $category = FinancialCategory::query()->create([
            'company_id' => $company->id,
            'name' => 'Cartao Recebiveis',
            'is_active' => true,
            'allow_receivable' => true,
            'allow_cash_movement' => true,
        ]);

        $service = app(InvoiceService::class);
        $result = $service->generateAccountReceivables($invoice->fresh(), [
            'payment_method' => PaymentMethod::CREDIT_CARD->value,
            'card_payment_profile_id' => $profile->id,
            'payment_date' => '2026-05-04',
            'financial_category_id' => $category->id,
        ], $user->id);

        $this->assertNotNull($result, $service->getMessage());

        $invoice->refresh();
        $receivable = $invoice->accountReceivables()->with('installments')->first();

        $this->assertNotNull($receivable);
        $this->assertNull($invoice->payment_condition);
        $this->assertSame($category->id, $invoice->fresh()->financial_category_id);
        $this->assertSame('2026-06-04', $receivable->due_date?->toDateString());
        $this->assertSame('2026-06-04', $receivable->installments->first()?->due_date?->toDateString());
        $this->assertSame($category->id, $receivable->installments->first()?->financial_category_id);
    }

    public function test_it_uses_company_default_financial_category_when_invoice_does_not_define_one(): void
    {
        $user = User::factory()->create();
        $company = Company::query()->create([
            'name' => 'Empresa Default Invoice',
            'document_number' => '12345678000198',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);
        $customer = Partner::query()->create([
            'name' => 'Cliente Default Invoice',
            'document_type' => 'CPF',
            'document_number' => '10987654321',
            'created_by' => $user->id,
        ]);
        $invoice = Invoice::query()->create([
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'invoice_number' => 'INV-DEFAULT-001',
            'invoice_date' => '2026-05-10',
            'status' => InvoiceStatus::CONFIRMED->value,
            'pending' => false,
            'confirmed' => true,
            'canceled' => false,
            'created_by' => $user->id,
        ]);

        $serviceModel = Service::query()->create([
            'company_id' => $company->id,
            'created_by' => $user->id,
            'service_code' => 'SRV-DEFAULT-001',
            'name' => 'Servico Default',
            'price' => 200,
            'tax_rate' => 5,
            'nbs_code' => '987654321',
            'cnae_code' => '6201500',
            'municipal_tax_code' => '01.01',
            'is_active' => true,
        ]);

        $serviceOrder = ServiceOrder::query()->create([
            'number' => 'SO-DEFAULT-001',
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'invoice_id' => $invoice->id,
            'order_date' => '2026-05-10',
            'status' => ServiceOrderState::CLOSED->value,
            'priority' => ServiceOrderPriority::NORMAL->value,
            'type' => ServiceOrderType::MAINTENANCE->value,
            'created_by' => $user->id,
        ]);

        ServiceOrderItem::query()->create([
            'service_order_id' => $serviceOrder->id,
            'service_id' => $serviceModel->id,
            'quantity' => 1,
            'unit_price' => 200,
            'discount_amount' => 0,
            'created_by' => $user->id,
        ]);

        $category = FinancialCategory::query()->create([
            'company_id' => $company->id,
            'name' => 'Recebimentos Padrao',
            'is_active' => true,
            'allow_receivable' => true,
            'allow_cash_movement' => true,
        ]);

        CompanyPreference::setDefaultReceivableFinancialCategoryId($category->id, $company->id);

        $service = app(InvoiceService::class);
        $result = $service->generateAccountReceivables($invoice->fresh(), [
            'payment_method' => PaymentMethod::PIX->value,
            'payment_condition' => PaymentCondition::CASH->value,
        ], $user->id);

        $this->assertNotNull($result, $service->getMessage());

        $installment = $invoice->fresh()->accountReceivables()->with('installments')->first()?->installments->first();

        $this->assertNotNull($installment);
        $this->assertSame($category->id, $invoice->fresh()->financial_category_id);
        $this->assertSame($category->id, $installment->financial_category_id);
    }
}
