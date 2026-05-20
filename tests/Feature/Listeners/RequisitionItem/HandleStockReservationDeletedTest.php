<?php

namespace Tests\Feature\Listeners\RequisitionItem;

use App\Enum\Product\OriginSalePrice;
use App\Enum\Product\Unit;
use App\Enum\Requisition\Status;
use App\Enum\StockMovement\Type;
use App\Events\RequisitionItem\RequisitionItemDeleted;
use App\Models\Company;
use App\Models\Partner;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HandleStockReservationDeletedTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_releases_only_the_pending_reserved_quantity_once(): void
    {
        [$user, $item, $stock] = $this->makeContext();

        RequisitionItemDeleted::dispatch($item->load('product'), $user->id);
        RequisitionItemDeleted::dispatch($item, $user->id);

        $releases = StockMovement::query()
            ->where('source_type', 'requisition_item')
            ->where('source_id', $item->id)
            ->where('type', Type::RESERVATION_RELEASE->value)
            ->get();

        $this->assertCount(1, $releases);
        $this->assertSame(2.0, $releases->first()->resolvedBaseQuantity());
        $this->assertSame(0.0, (float) $stock->fresh()->quantity_reserved);
    }

    private function makeContext(): array
    {
        $user = User::factory()->create();

        $company = Company::query()->create([
            'name' => 'Empresa Listener Estoque',
            'document_number' => '11222333000144',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);

        $customer = Partner::query()->create([
            'name' => 'Cliente Listener Estoque',
            'document_type' => 'CPF',
            'document_number' => '12345678901',
            'created_by' => $user->id,
        ]);

        $product = Product::query()->create([
            'company_id' => $company->id,
            'product_code' => 'PRD-LIST-001',
            'name' => 'Produto Listener Estoque',
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
            'number' => 'REQ-LIST-001',
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
            'quantity_in_base_unit' => 2,
            'conversion_factor_snapshot' => 1,
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
            'operational_unit' => 'UN',
            'operational_quantity' => 2,
            'base_unit' => 'UN',
            'base_quantity' => 2,
            'conversion_factor_snapshot' => 1,
            'quantity' => 2,
            'unit_price' => 50,
            'reason' => 'Reserva inicial do item',
            'source_type' => 'requisition_item',
            'source_id' => $item->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        return [$user, $item, $stock];
    }
}
