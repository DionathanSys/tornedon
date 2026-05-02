<?php

namespace Tests\Feature\Services\ProductionOrder;

use App\Enum\Product\OriginSalePrice;
use App\Enum\Product\Unit;
use App\Enum\ProductionOrder\Status;
use App\Enum\Quote\Destination;
use App\Models\Company;
use App\Models\Partner;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderItem;
use App\Models\ProductStock;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\ProductionOrder\DestinationHandlers\StockDestinationHandler;
use App\Services\Quote\Actions\ConvertToProductionOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Enum\ProductionOrder\DestinationType;
use App\Enum\ProductionOrder\Priority;

class ProductionOrderUnitConversionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_persists_base_snapshot_when_converting_quote_to_production_order(): void
    {
        $user = User::factory()->create();
        $company = $this->makeCompany($user);
        $customer = $this->makeCustomer($user);

        $product = Product::query()->create([
            'company_id' => $company->id,
            'created_by' => $user->id,
            'product_code' => 'PRD-PO-001',
            'name' => 'Produto Producao Alternativo',
            'unit' => Unit::JG->value,
            'origin_sale_price' => OriginSalePrice::FREE->value,
            'sale_price_value' => 100,
            'is_active' => true,
        ]);

        $product->alternativeUnitConversions()->create([
            'unit' => Unit::PC->value,
            'conversion_factor' => 0.125,
        ]);

        $quote = Quote::query()->create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'status' => \App\Enum\Quote\Status::APPROVED,
            'created_by' => $user->id,
        ]);

        QuoteItem::query()->create([
            'quote_id' => $quote->id,
            'product_id' => $product->id,
            'description' => 'Item alternativo para producao',
            'unit_of_measure' => Unit::PC->value,
            'quantity' => 16,
            'unit_price' => 10,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'sequence' => 1,
            'destination' => Destination::ORDER_PRODUCTION,
            'status' => \App\Enum\Quote\Status::DRAFT,
        ]);

        $productionOrder = (new ConvertToProductionOrder($user->id))->execute($quote->fresh()->load('items'));

        $this->assertNotNull($productionOrder);

        $item = $productionOrder->items()->first();

        $this->assertNotNull($item);
        $this->assertSame(Unit::PC->value, $item->unit_of_measure);
        $this->assertEquals(2.0, (float) $item->quantity_in_base_unit);
        $this->assertEquals(0.125, (float) $item->conversion_factor_snapshot);
    }

    public function test_stock_destination_handler_uses_approved_base_quantity_for_stock_entry(): void
    {
        $user = User::factory()->create();
        $company = $this->makeCompany($user);

        $product = Product::query()->create([
            'company_id' => $company->id,
            'created_by' => $user->id,
            'has_stock_control' => true,
            'product_code' => 'PRD-PO-002',
            'name' => 'Produto Estoque Alternativo',
            'unit' => Unit::JG->value,
            'origin_sale_price' => OriginSalePrice::FREE->value,
            'sale_price_value' => 100,
            'is_active' => true,
        ]);

        $product->alternativeUnitConversions()->create([
            'unit' => Unit::PC->value,
            'conversion_factor' => 0.125,
        ]);

        $productionOrder = ProductionOrder::query()->create([
            'company_id' => $company->id,
            'customer_id' => $this->makeCustomer($user)->id,
            'status' => Status::COMPLETED->value,
            'priority' => Priority::NORMAL->value,
            'destination_type' => DestinationType::STOCK->value,
            'created_by' => $user->id,
        ]);

        $item = ProductionOrderItem::query()->create([
            'production_order_id' => $productionOrder->id,
            'product_id' => $product->id,
            'description' => 'Item produzido em peças',
            'unit_of_measure' => Unit::PC->value,
            'quantity' => 16,
            'quantity_in_base_unit' => 2,
            'unit_price' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'quantity_produced' => 16,
            'quantity_approved' => 8,
            'quantity_approved_in_base_unit' => 1,
            'quantity_rejected' => 8,
            'conversion_factor_snapshot' => 0.125,
            'sequence' => 1,
        ]);

        ProductStock::query()->create([
            'product_id' => $product->id,
            'company_id' => $company->id,
            'quantity_total' => 0,
            'quantity_reserved' => 0,
            'is_active' => true,
            'allow_negative' => false,
            'created_by' => $user->id,
        ]);

        $result = (new StockDestinationHandler())->handle($productionOrder->fresh()->load('items.product'), $user->id);

        $this->assertTrue($result);

        $movement = StockMovement::query()->latest('id')->first();

        $this->assertNotNull($movement);
        $this->assertSame(Unit::PC->value, $movement->operational_unit);
        $this->assertEquals(8.0, (float) $movement->operational_quantity);
        $this->assertEquals(1.0, (float) $movement->base_quantity);
        $this->assertEquals(0.125, (float) $movement->conversion_factor_snapshot);
        $this->assertEquals(1.0, (float) $product->stock()->first()->quantity_total);
    }

    private function makeCompany(User $user): Company
    {
        return Company::query()->create([
            'name' => 'Empresa Producao',
            'document_number' => '55444333000122',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);
    }

    private function makeCustomer(User $user): Partner
    {
        return Partner::query()->create([
            'name' => 'Cliente Producao',
            'document_type' => 'CPF',
            'document_number' => '98765432100',
            'created_by' => $user->id,
        ]);
    }
}
