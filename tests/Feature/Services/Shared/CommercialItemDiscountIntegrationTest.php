<?php

namespace Tests\Feature\Services\Shared;

use App\Enum\Product\OriginSalePrice;
use App\Enum\Product\Unit;
use App\Enum\Requisition\Status;
use App\Enum\ServiceOrder\Priority;
use App\Enum\ServiceOrder\State;
use App\Enum\ServiceOrder\Type;
use App\Enum\Tax\IssExigibility;
use App\Models\Company;
use App\Models\Partner;
use App\Models\Product;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderItem;
use App\Models\User;
use App\Services\Requisition\RequisitionService;
use App\Services\ServiceOrder\ServiceOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommercialItemDiscountIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_applies_discount_through_service_order_service(): void
    {
        [$serviceOrder] = $this->makeServiceOrderContext();

        $service = app(ServiceOrderService::class);
        $result = $service->applyDiscount($serviceOrder, 30);

        $this->assertTrue($result, $service->getMessage());

        $serviceOrder->refresh()->load('items');

        $this->assertEquals(30.0, round((float) $serviceOrder->items->sum('discount_amount'), 2));
    }

    public function test_it_clears_discount_through_requisition_service(): void
    {
        [$requisition] = $this->makeRequisitionContext();

        $service = app(RequisitionService::class);
        $result = $service->clearDiscount($requisition);

        $this->assertTrue($result, $service->getMessage());

        $requisition->refresh()->load('items');

        foreach ($requisition->items as $item) {
            $this->assertSame(0.0, (float) $item->discount_amount);
            $this->assertSame(0.0, (float) $item->discount_percentage);
        }
    }

    private function makeServiceOrderContext(): array
    {
        $user = User::factory()->create();
        $company = Company::query()->create([
            'name' => 'Empresa Discount SO',
            'document_number' => '77112233000144',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);
        $customer = Partner::query()->create([
            'name' => 'Cliente Discount SO',
            'document_type' => 'CPF',
            'document_number' => '12345678999',
            'created_by' => $user->id,
        ]);
        $serviceModel = Service::query()->create([
            'company_id' => $company->id,
            'service_code' => 'SRV-DISCOUNT-001',
            'name' => 'Servico Discount',
            'price' => 100,
            'min_sale_price' => 100,
            'accept_customer_discount' => true,
            'cost' => 50,
            'category' => 'Geral',
            'is_active' => true,
            'requires_approval' => false,
            'iss_exigibility' => IssExigibility::EXIGIVEL,
            'created_by' => $user->id,
        ]);
        $serviceOrder = ServiceOrder::query()->create([
            'number' => 'OS-DISCOUNT-001',
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'order_date' => now()->toDateString(),
            'status' => State::OPEN,
            'priority' => Priority::NORMAL,
            'type' => Type::MAINTENANCE,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        ServiceOrderItem::query()->create([
            'service_order_id' => $serviceOrder->id,
            'service_id' => $serviceModel->id,
            'quantity' => 1,
            'unit_price' => 100,
            'discount_amount' => 0,
            'discount_percentage' => 0,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        ServiceOrderItem::query()->create([
            'service_order_id' => $serviceOrder->id,
            'service_id' => $serviceModel->id,
            'quantity' => 1,
            'unit_price' => 100,
            'discount_amount' => 0,
            'discount_percentage' => 0,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        return [$serviceOrder];
    }

    private function makeRequisitionContext(): array
    {
        $user = User::factory()->create();
        $company = Company::query()->create([
            'name' => 'Empresa Discount Req',
            'document_number' => '99112233000155',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);
        $customer = Partner::query()->create([
            'name' => 'Cliente Discount Req',
            'document_type' => 'CPF',
            'document_number' => '12345678988',
            'created_by' => $user->id,
        ]);
        $product = Product::query()->create([
            'company_id' => $company->id,
            'product_code' => 'PRD-DISCOUNT-001',
            'name' => 'Produto Discount',
            'unit' => Unit::UN,
            'origin_sale_price' => OriginSalePrice::FREE,
            'sale_price_value' => 100,
            'has_stock_control' => false,
            'is_active' => true,
            'created_by' => $user->id,
        ]);
        $requisition = Requisition::query()->create([
            'number' => 'REQ-DISCOUNT-001',
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'sale_date' => now()->toDateString(),
            'status' => Status::OPEN,
            'stock_reserved' => false,
            'stock_consumed' => false,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'product_id' => $product->id,
            'unit_of_measure' => Unit::UN->value,
            'quantity' => 1,
            'unit_price' => 100,
            'discount_amount' => 10,
            'discount_percentage' => 10,
            'stock_consumed' => false,
            'stock_consumed_at' => null,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        return [$requisition];
    }
}
