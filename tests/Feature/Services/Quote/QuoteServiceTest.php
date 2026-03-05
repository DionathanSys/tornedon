<?php

namespace Tests\Feature\Services\Quote;

use App\Enum\Payment\Condition;
use App\Enum\Payment\Method;
use App\Enum\Quote\Status;
use App\Models\Company;
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
        $this->company = Company::factory()->create(['created_by' => $this->user->id]);
    }

    public function test_can_create_quote()
    {
        $customer = Partner::factory()->create(['created_by' => $this->user->id]);

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
        $quote = Quote::factory()->create([
            'company_id' => $this->company->id,
            'created_by' => $this->user->id,
            'status' => Status::DRAFT
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

    public function test_can_list_quotes_by_company()
    {
        Quote::factory()->count(3)->create([
            'company_id' => $this->company->id,
            'created_by' => $this->user->id
        ]);

        // Outro orçamento de outra empresa para garantir filtro
        Quote::factory()->create();

        $list = $this->service->list($this->company->id);

        $this->assertCount(3, $list);
    }

    public function test_can_find_quote_by_id()
    {
        $quote = Quote::factory()->create([
            'company_id' => $this->company->id,
            'created_by' => $this->user->id
        ]);

        $found = $this->service->find($quote->id, $this->company->id);

        $this->assertNotNull($found);
        $this->assertEquals($quote->id, $found->id);
    }
}
