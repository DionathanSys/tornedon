<?php

namespace Tests\Feature\Services\Partner;

use App\Enum\Partner\Type as PartnerType;
use App\Enum\Payment\Condition;
use App\Enum\Payment\Method;
use App\Jobs\ImportCompanyPartnerCnpjDataJob;
use App\Models\Company;
use App\Models\CompanyPartner;
use App\Models\Partner;
use App\Models\User;
use App\Services\Partner\QuickCreateCustomerPartnerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class QuickCreateCustomerPartnerServiceTest extends TestCase
{
    use RefreshDatabase;

    private QuickCreateCustomerPartnerService $service;
    private User $user;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(QuickCreateCustomerPartnerService::class);
        $this->user = User::factory()->create();
        $this->company = Company::query()->create([
            'name' => 'Empresa Teste',
            'address' => [
                'city' => 'Sao Paulo',
                'state' => 'SP',
            ],
            'email' => 'empresa@example.com',
            'phone' => '11999999999',
            'is_active' => true,
            'created_by' => $this->user->id,
        ]);
    }

    public function test_can_create_customer_partner_without_dispatching_cnpj_job_for_cpf(): void
    {
        Bus::fake();

        $companyPartner = $this->service->create($this->user->id, $this->company->id, [
            'name' => 'Cliente Teste',
            'document_type' => 'cpf',
            'document_number' => '123.456.789-00',
            'state_tax_indicator' => '9',
            'invoice_threshold' => 150.50,
            'customer_discount_percentage' => 5,
            'payment_method' => Method::PIX->value,
            'payment_condition' => Condition::CASH->value,
            'is_active' => true,
            'import_cnpj_data' => false,
            'notify_service_order_closed' => true,
            'notify_requisition_closed' => true,
            'notify_production_order_closed' => false,
            'notify_invoice_confirmed' => true,
            'notify_fiscal_document_confirmed' => false,
        ]);

        $this->assertNotNull($companyPartner);
        $this->assertTrue($this->service->isSuccess());
        $this->assertSame(['customer'], $companyPartner->type);
        $this->assertTrue($companyPartner->notify_service_order_closed);
        $this->assertTrue($companyPartner->notify_requisition_closed);
        $this->assertTrue($companyPartner->notify_invoice_confirmed);
        $this->assertFalse($companyPartner->notify_production_order_closed);
        $this->assertFalse($companyPartner->notify_fiscal_document_confirmed);
        $this->assertEquals(150.50, $companyPartner->invoice_threshold);
        $this->assertSame(Method::PIX, $companyPartner->payment_method);
        $this->assertSame(Condition::CASH, $companyPartner->payment_condition);

        $this->assertDatabaseHas('partners', [
            'id' => $companyPartner->partner_id,
            'name' => 'Cliente Teste',
            'document_type' => 'cpf',
            'document_number' => '123.456.789-00',
        ]);

        $this->assertDatabaseHas('company_partner', [
            'id' => $companyPartner->id,
            'company_id' => $this->company->id,
            'partner_id' => $companyPartner->partner_id,
            'is_active' => true,
            'notify_service_order_closed' => true,
            'notify_requisition_closed' => true,
            'notify_invoice_confirmed' => true,
            'payment_method' => Method::PIX->value,
            'payment_condition' => Condition::CASH->value,
        ]);

        Bus::assertNotDispatched(ImportCompanyPartnerCnpjDataJob::class);
    }

    public function test_dispatches_cnpj_import_job_when_requested(): void
    {
        Bus::fake();

        $companyPartner = $this->service->create($this->user->id, $this->company->id, [
            'name' => 'Empresa Cliente',
            'document_type' => 'cnpj',
            'document_number' => '12.345.678/0001-99',
            'state_tax_indicator' => '1',
            'state_tax_id' => '123456789',
            'invoice_threshold' => 0,
            'customer_discount_percentage' => 0,
            'is_active' => true,
            'import_cnpj_data' => true,
            'notify_service_order_closed' => false,
            'notify_requisition_closed' => false,
            'notify_production_order_closed' => false,
            'notify_invoice_confirmed' => false,
            'notify_fiscal_document_confirmed' => true,
        ]);

        $this->assertNotNull($companyPartner);
        $this->assertTrue($this->service->isSuccess());

        Bus::assertDispatched(
            ImportCompanyPartnerCnpjDataJob::class,
            fn (ImportCompanyPartnerCnpjDataJob $job) => true
        );
    }

    public function test_reuses_existing_partner_and_upgrades_existing_association_to_customer(): void
    {
        Bus::fake();

        $partner = Partner::query()->create([
            'name' => 'Parceiro Existente',
            'document_type' => 'cpf',
            'document_number' => '987.654.321-00',
            'state_tax_indicator' => '9',
            'created_by' => $this->user->id,
        ]);

        $existingCompanyPartner = CompanyPartner::query()->create([
            'company_id' => $this->company->id,
            'partner_id' => $partner->id,
            'type' => [PartnerType::SUPPLIER->value],
            'invoice_threshold' => 0,
            'customer_discount_percentage' => 0,
            'is_active' => true,
            'notify_service_order_closed' => false,
            'notify_requisition_closed' => false,
            'notify_production_order_closed' => false,
            'notify_invoice_confirmed' => false,
            'notify_fiscal_document_confirmed' => false,
        ]);

        $companyPartner = $this->service->create($this->user->id, $this->company->id, [
            'name' => 'Parceiro Existente',
            'document_type' => 'cpf',
            'document_number' => '987.654.321-00',
            'state_tax_indicator' => '9',
            'invoice_threshold' => 200,
            'customer_discount_percentage' => 12.5,
            'payment_method' => Method::BANK_TRANSFER->value,
            'payment_condition' => Condition::DAYS_30->value,
            'is_active' => true,
            'import_cnpj_data' => false,
            'notify_service_order_closed' => true,
            'notify_requisition_closed' => false,
            'notify_production_order_closed' => true,
            'notify_invoice_confirmed' => true,
            'notify_fiscal_document_confirmed' => true,
        ]);

        $this->assertNotNull($companyPartner);
        $this->assertSame($existingCompanyPartner->id, $companyPartner->id);
        $this->assertSame($partner->id, $companyPartner->partner_id);
        $this->assertCount(1, Partner::query()->where('document_number', '987.654.321-00')->get());
        $this->assertContains(PartnerType::SUPPLIER->value, $companyPartner->type);
        $this->assertContains(PartnerType::CUSTOMER->value, $companyPartner->type);
        $this->assertTrue($companyPartner->notify_service_order_closed);
        $this->assertTrue($companyPartner->notify_production_order_closed);
        $this->assertTrue($companyPartner->notify_invoice_confirmed);
        $this->assertTrue($companyPartner->notify_fiscal_document_confirmed);
        $this->assertEquals(12.50, (float) $companyPartner->customer_discount_percentage);
        $this->assertSame(Method::BANK_TRANSFER, $companyPartner->payment_method);
        $this->assertSame(Condition::DAYS_30, $companyPartner->payment_condition);

        Bus::assertNotDispatched(ImportCompanyPartnerCnpjDataJob::class);
    }
}
