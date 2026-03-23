<?php

namespace Tests\Feature\Services\ServiceOrderItem;

use App\Enum\Partner\Type as PartnerType;
use App\Enum\ServiceOrder\Priority;
use App\Enum\ServiceOrder\State;
use App\Enum\ServiceOrder\Type;
use App\Models\Company;
use App\Models\CompanyPartner;
use App\Models\Partner;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\ServiceOrderItem\ServiceOrderItemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceOrderItemDiscountTest extends TestCase
{
    use RefreshDatabase;

    public function test_applies_customer_discount_limited_by_service_min_sale_price_on_service_order(): void
    {
        [$user, $serviceOrder, $service] = $this->makeServiceOrderContext(true, 20, 100, 95);

        $serviceLayer = new ServiceOrderItemService();
        $item = $serviceLayer->create([
            'service_order_id' => $serviceOrder->id,
            'service_id' => $service->id,
            'quantity' => 2,
            'unit_price' => 100,
        ], $user->id);

        $this->assertNotNull($item);
        $this->assertSame(10.0, (float) $item->discount_amount);
        $this->assertSame(5.0, round((float) $item->discount_percentage, 2));
        $this->assertFalse($serviceLayer->hasError());
    }

    public function test_rejects_manual_discount_above_min_sale_price_limit_on_update(): void
    {
        [$user, $serviceOrder, $service] = $this->makeServiceOrderContext(true, 20, 100, 95);

        $serviceLayer = new ServiceOrderItemService();
        $item = $serviceLayer->create([
            'service_order_id' => $serviceOrder->id,
            'service_id' => $service->id,
            'quantity' => 2,
            'unit_price' => 100,
        ], $user->id);

        $this->assertNotNull($item);

        $updated = $serviceLayer->update($item, [
            'discount_amount' => 12,
            'discount_percentage' => 6,
        ], $user->id);

        $this->assertNull($updated);
        $this->assertTrue($serviceLayer->hasError());
        $this->assertStringContainsString('preco minimo de venda', strtolower($serviceLayer->getMessageUser()));
    }

    private function makeServiceOrderContext(bool $acceptCustomerDiscount, float $customerDiscountPercentage, float $price, ?float $minSalePrice): array
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

        $serviceOrder = ServiceOrder::create([
            'number' => 'OS-TESTE-01',
            'customer_id' => $partner->id,
            'company_id' => $company->id,
            'order_date' => now()->toDateString(),
            'status' => State::OPEN,
            'priority' => Priority::NORMAL,
            'type' => Type::MAINTENANCE,
            'created_by' => $user->id,
        ]);

        $service = Service::create([
            'company_id' => $company->id,
            'service_code' => 'SRV-OS-01',
            'name' => 'Servico OS',
            'price' => $price,
            'min_sale_price' => $minSalePrice,
            'accept_customer_discount' => $acceptCustomerDiscount,
            'created_by' => $user->id,
        ]);

        return [$user, $serviceOrder, $service];
    }
}
