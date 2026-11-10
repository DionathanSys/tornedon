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
use App\Services\Requisition\Actions\CloseRequisitionAction;
use App\Services\Requisition\Actions\ReopenRequisitionAction;
use App\Services\Requisition\RequisitionStockWorkflow;
use App\Services\RequisitionItem\Actions\CreateRequisitionItemAction;
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

    public function test_it_recloses_after_adding_an_item_without_reserving_it_twice(): void
    {
        [$user, $requisition, $item] = $this->makeContext();
        $stock = ProductStock::where('product_id', $item->product_id)->firstOrFail();
        $stock->update(['quantity_total' => 3]);
        StockMovement::query()->where('source_id', $requisition->id)->update([
            'source_type' => 'requisition_item',
            'source_id' => $item->id,
        ]);
        $requisition->update(['status' => Status::CLOSED]);

        $reopen = new ReopenRequisitionAction($user->id);
        $this->assertNotNull($reopen->execute($requisition), (string) $reopen->getMessage());
        $this->assertEquals(0, $stock->fresh()->quantity_reserved);

        $create = new CreateRequisitionItemAction($user->id);
        $newItem = $create->execute([
            'requisition_id' => $requisition->id,
            'product_id' => $item->product_id,
            'unit_of_measure' => Unit::UN->value,
            'quantity' => 1,
            'unit_price' => 50,
        ]);
        $this->assertNotNull($newItem, (string) $create->getMessage());
        $this->assertEquals(1, $stock->fresh()->quantity_reserved);

        $close = new CloseRequisitionAction($user->id);
        $this->assertNotNull($close->execute($requisition->fresh()), (string) $close->getMessage());
        $this->assertSame(Status::CLOSED, $requisition->fresh()->status);
        $this->assertEquals(3, $stock->fresh()->quantity_reserved);
        $this->assertEquals(3, $stock->fresh()->quantity_total);

        // Another full cycle must neither lose nor duplicate reservations.
        $this->assertNotNull($reopen->execute($requisition->fresh()), (string) $reopen->getMessage());
        $this->assertEquals(0, $stock->fresh()->quantity_reserved);
        $this->assertNotNull($close->execute($requisition->fresh()), (string) $close->getMessage());
        $this->assertEquals(3, $stock->fresh()->quantity_reserved);
    }

    public function test_it_restores_legacy_reservations_even_when_release_and_reservation_totals_cancel_out(): void
    {
        [$user, $requisition, $item] = $this->makeContext();
        $stock = ProductStock::where('product_id', $item->product_id)->firstOrFail();
        $stock->update(['quantity_total' => 2]);
        $requisition->update(['status' => Status::CLOSED]);

        $reopen = new ReopenRequisitionAction($user->id);
        $this->assertNotNull($reopen->execute($requisition), (string) $reopen->getMessage());
        $this->assertEquals(0, $stock->fresh()->quantity_reserved);

        $close = new CloseRequisitionAction($user->id);
        $this->assertNotNull($close->execute($requisition->fresh()), (string) $close->getMessage());
        $this->assertEquals(2, $stock->fresh()->quantity_reserved);
    }

    public function test_it_reserves_only_the_missing_base_quantity_for_an_alternative_unit(): void
    {
        [$user, $requisition, $item] = $this->makeContext();
        $stock = ProductStock::where('product_id', $item->product_id)->firstOrFail();
        $stock->update(['quantity_total' => 4]);
        $item->update([
            'unit_of_measure' => Unit::PC->value,
            'quantity' => 8,
            'quantity_in_base_unit' => 4,
            'conversion_factor_snapshot' => 0.5,
        ]);

        $workflow = app(RequisitionStockWorkflow::class);
        $this->assertTrue($workflow->recreateReservationsIfNeeded($requisition, $user->id));
        $this->assertEquals(4, $stock->fresh()->quantity_reserved);
        $movement = StockMovement::latest('id')->firstOrFail();
        $this->assertEquals(2, $movement->base_quantity);
        $this->assertEquals(4, $movement->operational_quantity);
        $this->assertEquals(0.5, $movement->conversion_factor_snapshot);

        $count = StockMovement::count();
        $this->assertTrue($workflow->recreateReservationsIfNeeded($requisition, $user->id));
        $this->assertSame($count, StockMovement::count());
    }

    public function test_insufficient_stock_rolls_back_all_recreated_reservations_and_reports_the_reason(): void
    {
        [$user, $requisition, $item] = $this->makeContext();
        $stock = ProductStock::where('product_id', $item->product_id)->firstOrFail();
        $stock->update(['quantity_total' => 3, 'quantity_reserved' => 0]);
        StockMovement::query()->delete();
        $item->replicate()->save();

        $close = new CloseRequisitionAction($user->id);
        $this->assertNull($close->execute($requisition));
        $this->assertStringContainsString('Saldo disponível insuficiente para reservar', $close->getMessage());
        $this->assertSame(Status::OPEN, $requisition->fresh()->status);
        $this->assertEquals(0, $stock->fresh()->quantity_reserved);
        $this->assertEquals(3, $stock->fresh()->quantity_total);
        $this->assertSame(0, StockMovement::count());
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
