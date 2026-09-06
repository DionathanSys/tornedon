<?php

namespace Tests\Feature\Services\Quote;

use App\Enum\Quote\Status;
use App\Models\Company;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\User;
use App\Services\Quote\QuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuoteActionTest extends TestCase
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

    public function test_can_approve_quote()
    {
        $quote = Quote::factory()->create([
            'company_id' => $this->company->id,
            'status' => Status::SENT, 
        ]);

        $this->service->approve($quote, $this->user->id);

        $this->assertEquals(Status::APPROVED, $quote->fresh()->status);
        $this->assertTrue($this->service->isSuccess());
    }

    public function test_can_reject_quote()
    {
        $quote = Quote::factory()->create([
            'company_id' => $this->company->id,
            'status' => Status::SENT,
        ]);

        $this->service->reject($quote, 'Preço muito alto', $this->user->id);

        $this->assertEquals(Status::REJECTED, $quote->fresh()->status);
        $this->assertEquals('Preço muito alto', $quote->fresh()->rejected_reason);
        $this->assertTrue($this->service->isSuccess());
    }

    public function test_can_send_for_approval()
    {
        $quote = Quote::factory()->create([
            'company_id' => $this->company->id,
            'status' => Status::DRAFT,
        ]);
        QuoteItem::factory()->create([
            'quote_id' => $quote->id,
            'product_id' => null,
            'unit_of_measure' => 'UN',
            'quantity' => 1,
            'unit_price' => 100,
        ]);

        $this->service->sendForApproval($quote, $this->user->id);

        $this->assertEquals(Status::SENT, $quote->fresh()->status);
        $this->assertTrue($this->service->isSuccess());
    }

    public function test_cannot_approve_quote_in_draft()
    {
        $quote = Quote::factory()->create([
            'company_id' => $this->company->id,
            'status' => Status::DRAFT,
        ]);

        $this->service->approve($quote, $this->user->id);

        // O StateResolver provavelmente não permitirá essa transição se estiver seguindo o State Pattern
        $this->assertNotEquals(Status::APPROVED, $quote->fresh()->status);
        $this->assertTrue($this->service->hasError());
    }
}
