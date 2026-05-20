<?php

namespace Tests\Feature\Services;

use App\Enum\Requisition\Status;
use App\Enum\Product\OriginSalePrice;
use App\Enum\Product\Unit;
use App\Enum\ServiceOrder\Priority;
use App\Enum\ServiceOrder\State;
use App\Enum\ServiceOrder\Type;
use App\Enum\StockMovement\Type as StockMovementType;
use App\Enum\Tax\IssExigibility;
use App\Models\Company;
use App\Models\Partner;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Requisition\RequisitionService;
use App\Services\ServiceOrder\ServiceOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommercialDocumentDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_allows_service_order_deletion_when_it_has_items_and_no_linked_requisition(): void
    {
        [$user, $company, $customer] = $this->makeBaseContext();

        $serviceOrder = ServiceOrder::query()->create([
            'number' => 'OS-DELETE-001',
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'order_date' => now()->toDateString(),
            'status' => State::OPEN,
            'priority' => Priority::NORMAL,
            'type' => Type::MAINTENANCE,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $service = Service::query()->create([
            'company_id' => $company->id,
            'service_code' => 'SRV-DELETE-001',
            'name' => 'Servico Teste',
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

        $item = ServiceOrderItem::query()->create([
            'service_order_id' => $serviceOrder->id,
            'service_id' => $service->id,
            'quantity' => 1,
            'unit_price' => 100,
            'discount_amount' => 0,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $serviceOrderService = app(ServiceOrderService::class);

        $this->assertTrue($serviceOrderService->delete($serviceOrder));
        $this->assertFalse($serviceOrderService->hasError());
        $this->assertDatabaseMissing('service_order_items', ['id' => $item->id]);
        $this->assertDatabaseMissing('service_orders', ['id' => $serviceOrder->id]);
    }

    public function test_it_allows_requisition_deletion_when_items_are_not_consumed(): void
    {
        [$user, $company, $customer] = $this->makeBaseContext();

        $this->actingAs($user);

        $requisition = Requisition::query()->create([
            'number' => 'REQ-DELETE-001',
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'sale_date' => now()->toDateString(),
            'status' => Status::OPEN,
            'stock_reserved' => false,
            'stock_consumed' => false,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $product = Product::query()->create([
            'company_id' => $company->id,
            'product_code' => 'PRD-DELETE-001',
            'name' => 'Produto Teste',
            'unit' => Unit::UN,
            'origin_sale_price' => OriginSalePrice::FREE,
            'sale_price_value' => 100,
            'has_stock_control' => true,
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        $stock = ProductStock::query()->create([
            'product_id' => $product->id,
            'company_id' => $company->id,
            'quantity_total' => 10,
            'quantity_reserved' => 1,
            'is_active' => true,
            'allow_negative' => false,
            'created_by' => $user->id,
        ]);

        $item = RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'product_id' => $product->id,
            'unit_of_measure' => 'UN',
            'quantity' => 1,
            'quantity_in_base_unit' => 1,
            'conversion_factor_snapshot' => 1,
            'unit_price' => 100,
            'discount_amount' => 0,
            'stock_consumed' => false,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        StockMovement::query()->create([
            'product_stock_id' => $stock->id,
            'product_id' => $product->id,
            'company_id' => $company->id,
            'type' => StockMovementType::RESERVATION->value,
            'operational_unit' => 'UN',
            'operational_quantity' => 1,
            'base_unit' => 'UN',
            'base_quantity' => 1,
            'conversion_factor_snapshot' => 1,
            'quantity' => 1,
            'unit_price' => 100,
            'reason' => 'Reserva inicial',
            'source_type' => 'requisition_item',
            'source_id' => $item->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $requisitionService = app(RequisitionService::class);

        $this->assertTrue($requisitionService->delete($requisition));
        $this->assertFalse($requisitionService->hasError());
        $this->assertDatabaseMissing('requisition_items', ['id' => $item->id]);
        $this->assertDatabaseMissing('requisitions', ['id' => $requisition->id]);
        $this->assertDatabaseHas('stock_movements', [
            'source_type' => 'requisition_item',
            'source_id' => $item->id,
            'type' => StockMovementType::RESERVATION_RELEASE->value,
        ]);
        $this->assertSame(0.0, (float) $stock->fresh()->quantity_reserved);
    }

    public function test_it_blocks_requisition_deletion_when_it_has_consumed_items(): void
    {
        [$user, $company, $customer] = $this->makeBaseContext();

        $requisition = Requisition::query()->create([
            'number' => 'REQ-DELETE-002',
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'sale_date' => now()->toDateString(),
            'status' => Status::OPEN,
            'stock_reserved' => false,
            'stock_consumed' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $product = Product::query()->create([
            'company_id' => $company->id,
            'product_code' => 'PRD-DELETE-002',
            'name' => 'Produto Consumido',
            'unit' => Unit::UN,
            'origin_sale_price' => OriginSalePrice::FREE,
            'sale_price_value' => 100,
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        $item = RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'product_id' => $product->id,
            'unit_of_measure' => 'UN',
            'quantity' => 1,
            'unit_price' => 100,
            'discount_amount' => 0,
            'stock_consumed' => true,
            'stock_consumed_at' => now(),
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $requisitionService = app(RequisitionService::class);

        $this->assertFalse($requisitionService->delete($requisition));
        $this->assertTrue($requisitionService->hasError());
        $this->assertSame('Não é possível excluir requisição que possui itens com estoque consumido', $requisitionService->getMessage());
        $this->assertDatabaseHas('requisitions', ['id' => $requisition->id]);
        $this->assertDatabaseHas('requisition_items', ['id' => $item->id]);
    }

    private function makeBaseContext(): array
    {
        $user = User::factory()->create();

        $company = Company::query()->create([
            'name' => 'Empresa Exclusao',
            'document_number' => '12345678000199',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);

        $customer = Partner::query()->create([
            'name' => 'Cliente Exclusao',
            'document_type' => 'CPF',
            'document_number' => '12345678901',
            'created_by' => $user->id,
        ]);

        return [$user, $company, $customer];
    }
}
