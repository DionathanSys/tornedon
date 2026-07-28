<?php

namespace Tests\Feature\Services\StockMovement;

use App\Enum\Product\OriginSalePrice;
use App\Enum\Product\Unit;
use App\Enum\StockMovement\Type;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\User;
use App\Services\StockMovement\StockMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockMovementServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_converts_operational_quantity_to_base_quantity_before_updating_stock(): void
    {
        $user = User::factory()->create();

        $company = Company::query()->create([
            'name' => 'Empresa Estoque',
            'document_number' => '12345678000190',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);

        $product = Product::query()->create([
            'company_id' => $company->id,
            'created_by' => $user->id,
            'has_stock_control' => true,
            'name' => 'Produto em jogo',
            'product_code' => 'PRD-STK-001',
            'unit' => Unit::JG->value,
            'origin_sale_price' => OriginSalePrice::FREE->value,
            'sale_price_value' => 10,
            'is_active' => true,
        ]);

        $product->alternativeUnitConversions()->create([
            'unit' => Unit::PC->value,
            'conversion_factor' => 0.125,
        ]);

        $stock = ProductStock::query()->create([
            'product_id' => $product->id,
            'company_id' => $company->id,
            'quantity_total' => 0,
            'quantity_reserved' => 0,
            'is_active' => true,
            'allow_negative' => false,
            'created_by' => $user->id,
        ]);

        $service = app(StockMovementService::class);

        $movement = $service->create([
            'product_stock_id' => $stock->id,
            'product_id' => $product->id,
            'company_id' => $company->id,
            'type' => Type::ENTRY->value,
            'operational_unit' => Unit::PC->value,
            'quantity' => 16,
            'unit_price' => 10,
            'reason' => 'Compra em peça',
            'source_type' => 'manual',
            'source_id' => 0,
        ], $user->id);

        $this->assertNotNull($movement);
        $this->assertTrue($service->isSuccess());
        $this->assertSame('PC', $movement->operational_unit);
        $this->assertEquals(16.0, (float) $movement->operational_quantity);
        $this->assertSame('JG', $movement->base_unit);
        $this->assertEquals(2.0, (float) $movement->base_quantity);
        $this->assertEquals(2.0, (float) $movement->quantity);
        $this->assertEquals(0.125, (float) $movement->conversion_factor_snapshot);
        $this->assertEquals(160.0, (float) $movement->total_amount);

        $this->assertEquals(2.0, (float) $stock->fresh()->quantity_total);
        $this->assertEquals(80.0, (float) $stock->fresh()->average_cost);
        $this->assertEquals(80.0, (float) $stock->fresh()->last_cost);
    }
}
