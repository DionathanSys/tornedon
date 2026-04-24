<?php

namespace Tests\Feature\Services\Quote;

use App\Enum\Payment\Condition;
use App\Enum\Payment\Method;
use App\Enum\Quote\Status;
use App\Models\Company;
use App\Models\CompanyPartner;
use App\Models\CompanyPreference;
use App\Models\Partner;
use App\Models\Quote;
use App\Models\User;
use App\Services\Quote\QuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuoteServiceTest extends TestCase
{
    use RefreshDatabase;

    private QuoteService $service;
    private User $user;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new QuoteService();
        $this->user = User::factory()->create();
        $this->company = Company::query()->create([
            'name' => 'Empresa Quote Teste',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $this->user->id,
        ]);
    }

    public function test_can_create_quote()
    {
        $customer = Partner::query()->create([
            'name' => 'Cliente Teste',
            'document_type' => 'cpf',
            'document_number' => '12345678901',
            'created_by' => $this->user->id,
        ]);

        $data = [
            'company_id' => $this->company->id,
            'customer_id' => $customer->id,
            'payment_method' => Method::PIX->value,
            'payment_condition' => Condition::CASH->value,
            'description' => 'Orçamento de teste via Service',
        ];

        $quote = $this->service->create($data, $this->user->id);

        $this->assertInstanceOf(Quote::class, $quote);
        $this->assertEquals(Status::DRAFT, $quote->status);
        $this->assertDatabaseHas('quotes', [
            'id' => $quote->id,
            'description' => 'Orçamento de teste via Service',
        ]);
        $this->assertTrue($this->service->isSuccess());
    }

    public function test_can_update_quote()
    {
        $quote = Quote::query()->create([
            'company_id' => $this->company->id,
            'customer_id' => Partner::query()->create([
                'name' => 'Cliente Update',
                'document_type' => 'cpf',
                'document_number' => '12345678902',
                'created_by' => $this->user->id,
            ])->id,
            'created_by' => $this->user->id,
            'status' => Status::DRAFT,
            'payment_method' => Method::PIX->value,
            'payment_condition' => Condition::CASH->value,
        ]);

        $updateData = [
            'description' => 'Descrição atualizada',
            'payment_method' => Method::STORE_CREDIT->value,
        ];

        $updatedQuote = $this->service->update($quote, $updateData, $this->user->id);

        $this->assertEquals('Descrição atualizada', $updatedQuote->description);
        $this->assertEquals(Method::STORE_CREDIT, $updatedQuote->payment_method);
        $this->assertTrue($this->service->isSuccess());
    }

    public function test_it_uses_customer_payment_defaults_when_creating_quote_without_explicit_payment(): void
    {
        $customer = Partner::query()->create([
            'name' => 'Cliente Defaults',
            'document_type' => 'cpf',
            'document_number' => '12345678903',
            'created_by' => $this->user->id,
        ]);

        CompanyPreference::setDefaultPaymentMethod(Method::PIX->value, $this->company->id);
        CompanyPreference::setDefaultPaymentCondition(Condition::CASH->value, $this->company->id);

        CompanyPartner::query()->create([
            'company_id' => $this->company->id,
            'partner_id' => $customer->id,
            'type' => ['customer'],
            'invoice_threshold' => 0,
            'customer_discount_percentage' => 0,
            'payment_method' => Method::BANK_SLIP->value,
            'payment_condition' => Condition::DAYS_30->value,
            'is_active' => true,
        ]);

        $quote = $this->service->create([
            'company_id' => $this->company->id,
            'customer_id' => $customer->id,
            'description' => 'Orçamento herdando pagamento do cliente',
        ], $this->user->id);

        $this->assertNotNull($quote);
        $this->assertSame(Method::BANK_SLIP, $quote->payment_method);
        $this->assertSame(Condition::DAYS_30, $quote->payment_condition);
    }

    public function test_can_list_quotes_by_company()
    {
        $customer = Partner::query()->create([
            'name' => 'Cliente Lista',
            'document_type' => 'cpf',
            'document_number' => '12345678904',
            'created_by' => $this->user->id,
        ]);

        foreach (range(1, 3) as $index) {
            Quote::query()->create([
                'company_id' => $this->company->id,
                'customer_id' => $customer->id,
                'created_by' => $this->user->id,
                'status' => Status::DRAFT,
                'payment_method' => Method::PIX->value,
                'payment_condition' => Condition::CASH->value,
                'description' => "Quote {$index}",
            ]);
        }

        $otherCompany = Company::query()->create([
            'name' => 'Outra Empresa',
            'address' => ['city' => 'Campinas', 'state' => 'SP'],
            'created_by' => $this->user->id,
        ]);

        Quote::query()->create([
            'company_id' => $otherCompany->id,
            'customer_id' => $customer->id,
            'created_by' => $this->user->id,
            'status' => Status::DRAFT,
            'payment_method' => Method::PIX->value,
            'payment_condition' => Condition::CASH->value,
        ]);

        $list = $this->service->list($this->company->id);

        $this->assertCount(3, $list);
    }

    public function test_can_find_quote_by_id()
    {
        $customer = Partner::query()->create([
            'name' => 'Cliente Find',
            'document_type' => 'cpf',
            'document_number' => '12345678905',
            'created_by' => $this->user->id,
        ]);

        $quote = Quote::query()->create([
            'company_id' => $this->company->id,
            'customer_id' => $customer->id,
            'created_by' => $this->user->id,
            'status' => Status::DRAFT,
            'payment_method' => Method::PIX->value,
            'payment_condition' => Condition::CASH->value,
        ]);

        $found = $this->service->find($quote->id, $this->company->id);

        $this->assertNotNull($found);
        $this->assertEquals($quote->id, $found->id);
    }
}
