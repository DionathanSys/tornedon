<?php

namespace Tests\Feature\Services\QuoteItem;

use App\Enum\Partner\Type as PartnerType;
use App\Enum\Payment\Condition;
use App\Enum\Payment\Method;
use App\Enum\Quote\Destination;
use App\Enum\Quote\Status;
use App\Models\Company;
use App\Models\CompanyPartner;
use App\Models\Partner;
use App\Models\Quote;
use App\Models\Service;
use App\Models\User;
use App\Services\QuoteItem\QuoteItemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuoteItemDiscountTest extends TestCase
{
    use RefreshDatabase;

    public function test_applies_customer_discount_limited_by_service_min_sale_price(): void
    {
        [$user, $company, $partner, $quote, $service] = $this->makeQuoteContext(true, 20, 100, 95);

        $serviceLayer = new QuoteItemService();
        $item = $serviceLayer->create([
            'quote_id' => $quote->id,
            'service_id' => $service->id,
            'description' => 'Servico com desconto automatico',
            'quantity' => 2,
            'unit_of_measure' => 'UN',
            'unit_price' => 100,
            'destination' => Destination::ORDER_SERVICE->value,
            'status' => Status::DRAFT->value,
        ], $user->id);

        $this->assertNotNull($item);
        $this->assertSame(10.0, (float) $item->discount_amount);
        $this->assertSame(5.0, round((float) $item->discount_percentage, 2));
        $this->assertFalse($serviceLayer->hasError());
    }

    public function test_rejects_manual_discount_that_breaks_service_min_sale_price(): void
    {
        [$user, $company, $partner, $quote, $service] = $this->makeQuoteContext(true, 20, 100, 95);

        $serviceLayer = new QuoteItemService();
        $item = $serviceLayer->create([
            'quote_id' => $quote->id,
            'service_id' => $service->id,
            'description' => 'Servico com desconto invalido',
            'quantity' => 2,
            'unit_of_measure' => 'UN',
            'unit_price' => 100,
            'discount_amount' => 12,
            'discount_percentage' => 6,
            'destination' => Destination::ORDER_SERVICE->value,
            'status' => Status::DRAFT->value,
        ], $user->id);

        $this->assertNull($item);
        $this->assertTrue($serviceLayer->hasError());
        $this->assertStringContainsString('preco minimo de venda', strtolower($serviceLayer->getMessageUser()));
    }

    private function makeQuoteContext(bool $acceptCustomerDiscount, float $customerDiscountPercentage, float $price, ?float $minSalePrice): array
    {
        $user = User::factory()->create();
        $company = Company::factory()->create(['created_by' => $user->id]);
        $partner = Partner::factory()->create(['created_by' => $user->id]);

        CompanyPartner::create([
            'company_id' => $company->id,
            'partner_id' => $partner->id,
            'type' => [PartnerType::CUSTOMER->value],
            'invoice_threshold' => 0,
            'customer_discount_percentage' => $customerDiscountPercentage,
            'is_active' => true,
        ]);

        $quote = Quote::create([
            'company_id' => $company->id,
            'customer_id' => $partner->id,
            'status' => Status::DRAFT,
            'payment_method' => Method::PIX,
            'payment_condition' => Condition::CASH,
            'valid_until' => now()->addDays(30),
            'created_by' => $user->id,
        ]);

        $service = Service::create([
            'company_id' => $company->id,
            'service_code' => 'SRV-QT-01',
            'name' => 'Servico quote',
            'price' => $price,
            'min_sale_price' => $minSalePrice,
            'accept_customer_discount' => $acceptCustomerDiscount,
            'created_by' => $user->id,
        ]);

        return [$user, $company, $partner, $quote, $service];
    }
}
