<?php

namespace Tests\Feature\Services\ProductionRequest;

use App\Enum\Financial\CashMovementDirection;
use App\Enum\Financial\FinancialAccountType;
use App\Enum\Payment\Condition as PaymentCondition;
use App\Enum\Payment\Method as PaymentMethod;
use App\Models\CashMovement;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\Partner;
use App\Models\Product;
use App\Models\User;
use App\Services\ProductionRequest\ProductionRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionRequestServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProductionRequestService $service;

    private User $user;

    private Company $company;

    private Partner $customer;

    private Product $product;

    private FinancialCategory $financialCategory;

    private FinancialAccount $financialAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ProductionRequestService::class);
        $this->user = User::factory()->create();

        $this->company = Company::query()->create([
            'name' => 'Empresa PR',
            'document_number' => '12345678000177',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $this->user->id,
        ]);

        $this->customer = Partner::query()->create([
            'name' => 'Cliente PR',
            'document_type' => 'CPF',
            'document_number' => '12345678901',
            'created_by' => $this->user->id,
        ]);

        $this->product = Product::query()->create([
            'company_id' => $this->company->id,
            'product_code' => 'PRTESTE01',
            'name' => 'Produto PR',
            'unit' => 'UN',
            'origin_sale_price' => 'free',
            'sale_price_value' => 15,
            'is_active' => true,
            'created_by' => $this->user->id,
        ]);

        $this->financialCategory = FinancialCategory::query()->create([
            'company_id' => $this->company->id,
            'name' => 'Recebimentos PR',
            'allow_receivable' => true,
            'allow_cash_movement' => true,
            'is_active' => true,
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);

        $this->financialAccount = FinancialAccount::query()->create([
            'company_id' => $this->company->id,
            'name' => 'Caixa PR',
            'type' => FinancialAccountType::CASH->value,
            'opening_balance' => 0,
            'is_active' => true,
            'is_default' => true,
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);
    }

    public function test_it_delivers_request_and_generates_account_receivable(): void
    {
        $request = $this->createRequest([
            'customer_id' => $this->customer->id,
            'manual_counterparty_name' => null,
            'payment_method' => PaymentMethod::PIX->value,
            'payment_condition' => PaymentCondition::DAYS_30_60->value,
        ]);

        $this->attachItem($request, 10, 2);

        $delivered = $this->service->deliver($request->fresh(), [
            'delivered_at' => '2026-07-05 10:30:00',
        ], $this->user->id);

        $this->assertNotNull($delivered, $this->service->getMessageUser());
        $this->assertNotNull($delivered->account_receivable_id);
        $this->assertSame('2026-07-05 10:30:00', $delivered->delivered_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-05 10:30:00', $delivered->closed_at?->format('Y-m-d H:i:s'));

        $receivable = $delivered->accountReceivable()->with('installments')->first();

        $this->assertNotNull($receivable);
        $this->assertSame(20.0, (float) $receivable->due_amount);
        $this->assertSame('2026-08-04', $receivable->due_date?->toDateString());
        $this->assertCount(2, $receivable->installments);
        $this->assertSame(
            ['2026-08-04', '2026-09-03'],
            $receivable->installments->sortBy('sequence_number')->pluck('due_date')->map(fn ($date) => $date?->toDateString())->all()
        );
    }

    public function test_it_allows_manual_counterparty_when_delivering_request(): void
    {
        $request = $this->createRequest([
            'customer_id' => null,
            'manual_counterparty_name' => 'Cliente Avulso PR',
            'payment_method' => PaymentMethod::PIX->value,
            'payment_condition' => PaymentCondition::CASH->value,
        ]);

        $this->attachItem($request, 25, 1);

        $delivered = $this->service->deliver($request->fresh(), [], $this->user->id);

        $this->assertNotNull($delivered, $this->service->getMessageUser());
        $this->assertSame('Cliente Avulso PR', $delivered->accountReceivable?->manual_counterparty_name);
        $this->assertNull($delivered->accountReceivable?->customer_id);
    }

    public function test_it_can_register_receipt_while_delivering_request(): void
    {
        $request = $this->createRequest([
            'customer_id' => $this->customer->id,
            'manual_counterparty_name' => null,
            'payment_method' => PaymentMethod::PIX->value,
            'payment_condition' => PaymentCondition::CASH->value,
        ]);

        $this->attachItem($request, 40, 1);

        $delivered = $this->service->deliver($request->fresh(), [
            'mark_as_received' => true,
            'received_at' => '2026-07-05',
            'financial_account_id' => $this->financialAccount->id,
        ], $this->user->id);

        $this->assertNotNull($delivered, $this->service->getMessageUser());

        $receivable = $delivered->accountReceivable()->with('installments.payments')->first();

        $this->assertNotNull($receivable);
        $this->assertTrue((bool) $receivable->paid);
        $this->assertSame(40.0, (float) $receivable->paid_amount);
        $this->assertSame('2026-07-05', $receivable->paid_date?->toDateString());
        $this->assertCount(1, $receivable->installments);
        $this->assertCount(1, $receivable->installments->first()->payments);

        $movement = CashMovement::query()->sole();

        $this->assertSame(CashMovementDirection::INFLOW, $movement->direction);
        $this->assertSame(40.0, (float) $movement->amount);
        $this->assertSame($this->financialAccount->id, $movement->financial_account_id);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createRequest(array $overrides = [])
    {
        $request = $this->service->create(array_merge([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'order_date' => '2026-07-05',
            'payment_method' => PaymentMethod::PIX->value,
            'payment_condition' => PaymentCondition::CASH->value,
            'financial_category_id' => $this->financialCategory->id,
            'observations' => 'Pedido teste',
        ], $overrides), $this->user->id);

        $this->assertNotNull($request, $this->service->getMessageUser());

        return $request;
    }

    private function attachItem($request, float $unitPrice, float $quantity): void
    {
        $request->items()->create([
            'product_id' => $this->product->id,
            'description' => 'Item teste',
            'unit_of_measure' => 'UN',
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'sequence' => 1,
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);
    }
}
