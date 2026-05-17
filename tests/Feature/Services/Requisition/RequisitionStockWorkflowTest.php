<?php

namespace Tests\Feature\Services\Requisition;

use App\Enum\Product\OriginSalePrice;
use App\Enum\Product\Unit;
use App\Enum\Requisition\Status;
use App\Enum\StockMovement\Type;
use App\Models\Company;
use App\Models\Partner;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Requisition\RequisitionStockWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RequisitionStockWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_releases_reservations_for_pending_items(): void
    {
        [$user, $requisition, $item] = $this->makeContext();

        $workflow = app(RequisitionStockWorkflow::class);
        $result = $workflow->releaseReservations($requisition, $user->id);

        $this->assertTrue($result, $workflow->getMessage());
        $this->assertDatabaseHas('stock_movements', [
            'source_type' => 'requisition',
            'source_id' => $requisition->id,
            'product_id' => $item->product_id,
            'type' => Type::RESERVATION_RELEASE->value,
        ]);
    }

    private function makeContext(): array
    {
        $user = User::factory()->create();

        $company = Company::query()->create([
            'name' => 'Empresa Stock Workflow',
            'document_number' => '88997766000155',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);

        $customer = Partner::query()->create([
            'name' => 'Cliente Stock Workflow',
            'document_type' => 'CPF',
            'document_number' => '12312312312',
            'created_by' => $user->id,
        ]);

        $product = Product::query()->create([
            'company_id' => $company->id,
            'product_code' => 'PRD-STK-001',
            'name' => 'Produto Stock Workflow',
            'unit' => Unit::UN,
            'origin_sale_price' => OriginSalePrice::FREE,
            'sale_price_value' => 50,
            'has_stock_control' => true,
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        $stock = ProductStock::query()->create([
            'product_id' => $product->id,
            'company_id' => $company->id,
            'quantity_total' => 10,
            'quantity_reserved' => 2,
            'is_active' => true,
            'allow_negative' => false,
            'created_by' => $user->id,
        ]);

        $requisition = Requisition::query()->create([
            'number' => 'REQ-STK-001',
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'sale_date' => now()->toDateString(),
            'status' => Status::OPEN,
            'stock_reserved' => true,
            'stock_consumed' => false,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $item = RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'product_id' => $product->id,
            'unit_of_measure' => 'UN',
            'quantity' => 2,
            'unit_price' => 50,
            'discount_amount' => 0,
            'stock_consumed' => false,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        StockMovement::query()->create([
            'product_stock_id' => $stock->id,
            'product_id' => $product->id,
            'company_id' => $company->id,
            'type' => Type::RESERVATION->value,
            'quantity' => 2,
            'unit_price' => 50,
            'reason' => 'Reserva inicial',
            'source_type' => 'requisition',
            'source_id' => $requisition->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        return [$user, $requisition, $item];
    }
}
