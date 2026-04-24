<?php

namespace Tests\Feature\Services\Quote;

use App\Enum\Payment\Condition;
use App\Enum\Payment\Method;
use App\Enum\Quote\Destination;
use App\Enum\Quote\Status;
use App\Events\Quote\QuoteApproved;
use App\Listeners\Quote\CreateProductionOrderFromApprovedQuoteListener;
use App\Listeners\Quote\CreateServiceOrderFromApprovedQuoteListener;
use App\Models\Company;
use App\Models\CompanyPartner;
use App\Models\CompanyPreference;
use App\Models\Partner;
use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Service;
use App\Models\User;
use App\Services\Quote\QuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuoteApprovalAutoCreateOrdersTest extends TestCase
{
    use RefreshDatabase;

    private QuoteService $service;
    private User $user;
    private Company $company;
    private Partner $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new QuoteService();
        $this->user = User::factory()->create();
        $this->company = Company::create([
            'name' => 'Empresa Teste',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $this->user->id,
        ]);
        $this->customer = Partner::create([
            'name' => 'Cliente Teste',
            'document_type' => 'cpf',
            'document_number' => '12345678901',
            'created_by' => $this->user->id,
        ]);
    }

    public function test_approve_quote_does_not_duplicate_production_order_when_listener_runs_again(): void
    {
        $quote = Quote::create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'status' => Status::SENT,
            'payment_method' => Method::PIX,
            'payment_condition' => Condition::CASH,
            'created_by' => $this->user->id,
        ]);

        $product = Product::create([
            'product_code' => 'P-001',
            'name' => 'Produto Teste',
            'company_id' => $this->company->id,
            'unit' => 'UN',
            'created_by' => $this->user->id,
        ]);

        QuoteItem::create([
            'quote_id' => $quote->id,
            'product_id' => $product->id,
            'description' => 'Item para producao',
            'unit_of_measure' => 'UN',
            'quantity' => 2,
            'unit_price' => 150,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'sequence' => 1,
            'destination' => Destination::ORDER_PRODUCTION,
            'status' => Status::DRAFT,
        ]);

        $approvedQuote = $this->service->approve($quote, $this->user->id);

        $this->assertNotNull($approvedQuote);
        $this->assertSame(1, $quote->fresh()->productionOrder()->count());

        $listener = app(CreateProductionOrderFromApprovedQuoteListener::class);
        $listener->handle(new QuoteApproved($quote->fresh(), $this->user->id));

        $quote->refresh();

        $this->assertSame(1, $quote->productionOrder()->count());
        $this->assertSame(1, $quote->productionOrder->items()->count());
    }

    public function test_approve_quote_does_not_duplicate_service_order_when_listener_runs_again(): void
    {
        $quote = Quote::create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'status' => Status::SENT,
            'payment_method' => Method::PIX,
            'payment_condition' => Condition::CASH,
            'created_by' => $this->user->id,
        ]);

        $service = Service::create([
            'service_code' => 'S-001',
            'name' => 'Servico Teste',
            'price' => 80,
            'company_id' => $this->company->id,
            'created_by' => $this->user->id,
        ]);

        QuoteItem::create([
            'quote_id' => $quote->id,
            'service_id' => $service->id,
            'description' => 'Item para servico',
            'unit_of_measure' => 'H',
            'quantity' => 3,
            'unit_price' => 80,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'sequence' => 1,
            'destination' => Destination::ORDER_SERVICE,
            'status' => Status::DRAFT,
            'estimated_production_hours' => 4,
        ]);

        $approvedQuote = $this->service->approve($quote, $this->user->id);

        $this->assertNotNull($approvedQuote);
        $this->assertSame(1, $quote->fresh()->serviceOrders()->count());

        $listener = app(CreateServiceOrderFromApprovedQuoteListener::class);
        $listener->handle(new QuoteApproved($quote->fresh(), $this->user->id));

        $quote->refresh();

        $this->assertSame(1, $quote->serviceOrders()->count());
        $this->assertSame(1, $quote->serviceOrders()->first()->items()->count());
    }

    public function test_approve_quote_generates_service_order_and_requisition_using_customer_payment_defaults_when_quote_has_no_payment(): void
    {
        CompanyPreference::setDefaultPaymentMethod(Method::PIX->value, $this->company->id);
        CompanyPreference::setDefaultPaymentCondition(Condition::CASH->value, $this->company->id);

        CompanyPartner::query()->create([
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => ['customer'],
            'invoice_threshold' => 0,
            'customer_discount_percentage' => 0,
            'payment_method' => Method::BANK_TRANSFER->value,
            'payment_condition' => Condition::DAYS_45->value,
            'is_active' => true,
        ]);

        $quote = Quote::create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'status' => Status::SENT,
            'created_by' => $this->user->id,
        ]);

        $service = Service::create([
            'service_code' => 'S-002',
            'name' => 'Servico Teste 2',
            'price' => 80,
            'company_id' => $this->company->id,
            'created_by' => $this->user->id,
        ]);

        $product = Product::create([
            'product_code' => 'P-002',
            'name' => 'Produto Teste 2',
            'company_id' => $this->company->id,
            'unit' => 'UN',
            'created_by' => $this->user->id,
        ]);

        QuoteItem::create([
            'quote_id' => $quote->id,
            'service_id' => $service->id,
            'description' => 'Item para servico',
            'unit_of_measure' => 'H',
            'quantity' => 1,
            'unit_price' => 80,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'sequence' => 1,
            'destination' => Destination::ORDER_SERVICE,
            'status' => Status::DRAFT,
            'estimated_production_hours' => 2,
        ]);

        QuoteItem::create([
            'quote_id' => $quote->id,
            'product_id' => $product->id,
            'description' => 'Item para requisicao',
            'unit_of_measure' => 'UN',
            'quantity' => 1,
            'unit_price' => 150,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'sequence' => 2,
            'destination' => Destination::REQUISITION,
            'status' => Status::DRAFT,
        ]);

        $approvedQuote = $this->service->approve($quote, $this->user->id);

        $this->assertNotNull($approvedQuote);

        $serviceOrder = $quote->fresh()->serviceOrders()->first();
        $requisition = $quote->fresh()->requisitions()->first();

        $this->assertNotNull($serviceOrder);
        $this->assertNotNull($requisition);
        $this->assertSame(Method::BANK_TRANSFER, $serviceOrder->payment_method);
        $this->assertSame(Condition::DAYS_45, $serviceOrder->payment_condition);
        $this->assertSame(Method::BANK_TRANSFER, $requisition->payment_method);
        $this->assertSame(Condition::DAYS_45, $requisition->payment_condition);
    }
}
