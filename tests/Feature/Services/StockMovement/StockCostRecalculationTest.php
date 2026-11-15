<?php

namespace Tests\Feature\Services\StockMovement;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\StockMovement\Actions\CalculateProductStockSnapshotAction;
use App\Services\StockMovement\Actions\CalculateStockMovementStateAction;
use App\Services\StockMovement\Actions\RecalculateProductStockFromMovementsAction;
use App\Services\StockMovement\StockMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockCostRecalculationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Product $product;

    private ProductStock $stock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $company = Company::create(['name' => 'Estoque', 'document_number' => '12345678000190',
            'address' => ['city' => 'São Paulo', 'state' => 'SP'], 'created_by' => $this->user->id]);
        $this->product = Product::create(['company_id' => $company->id, 'created_by' => $this->user->id,
            'name' => 'Produto', 'product_code' => 'COST-01', 'unit' => 'UN', 'origin_sale_price' => 'free',
            'has_stock_control' => true, 'is_active' => true]);
        $this->stock = ProductStock::create(['product_id' => $this->product->id, 'company_id' => $company->id,
            'quantity_total' => 0, 'quantity_reserved' => 0, 'average_cost' => 0,
            'allow_negative' => false, 'is_active' => true]);
    }

    public function test_full_sale_then_new_purchase_rebuilds_at_the_new_cost(): void
    {
        $this->move('entry', 10, 10);
        $this->move('exit', 10, 30);
        $this->move('entry', 10, 20);

        $this->assertEquals(10, $this->stock->fresh()->quantity_total);
        $this->assertEquals(20, $this->stock->fresh()->average_cost);
        $this->assertRebuildMatches();
    }

    public function test_partial_sale_uses_only_the_remaining_stock_value(): void
    {
        $this->move('entry', 10, 10);
        $this->move('exit', 5, 30);
        $this->move('entry', 15, 20);

        $this->assertEquals(17.5, $this->stock->fresh()->average_cost);
        $this->assertRebuildMatches();
    }

    public function test_conversion_snapshot_is_used_even_after_the_factor_changes(): void
    {
        $conversion = $this->product->alternativeUnitConversions()->create(['unit' => 'CX', 'conversion_factor' => 12]);
        $this->move('entry', 2, 120, 'CX');
        $this->move('exit', 6, 30);
        $conversion->update(['conversion_factor' => 24]);

        $this->assertEquals(18, $this->stock->fresh()->quantity_total);
        $this->assertEquals(10, $this->stock->fresh()->average_cost);
        $this->assertEquals(10, $this->stock->fresh()->last_cost);
        $this->assertRebuildMatches();
    }

    public function test_reservations_releases_and_costless_reversals_do_not_change_costs(): void
    {
        $this->move('entry', 10, 10);
        $this->move('reservation', 3);
        $this->move('reservation_release', 2);
        $this->move('exit', 2, 0);
        $this->move('entry', 2, 0);

        $this->assertEquals(10, $this->stock->fresh()->average_cost);
        $this->assertEquals(1, $this->stock->fresh()->quantity_reserved);
        $this->assertRebuildMatches();
    }

    public function test_priced_adjustment_and_return_follow_the_same_weighted_rule(): void
    {
        $this->move('entry', 10, 10);
        $this->move('adjustment', 10, 20);
        $this->move('return', 10, 30);
        $this->move('loss', 3);

        $this->assertEquals(20, $this->stock->fresh()->average_cost);
        $this->assertEquals(27, $this->stock->fresh()->quantity_total);
        $this->assertRebuildMatches();
    }

    public function test_negative_stock_does_not_generate_a_fictitious_weighted_cost(): void
    {
        $this->stock->update(['allow_negative' => true]);
        $this->move('entry', 2, 10);
        $this->move('exit', 5, 30);
        $this->move('entry', 1, 20);
        $this->assertEquals(-2, $this->stock->fresh()->quantity_total);
        $this->assertEquals(20, $this->stock->fresh()->average_cost);
        $this->move('entry', 4, 25);

        $this->assertEquals(2, $this->stock->fresh()->quantity_total);
        $this->assertEquals(25, $this->stock->fresh()->average_cost);
        $this->assertRebuildMatches();
    }

    public function test_initial_balance_is_preserved_after_deleting_all_movements(): void
    {
        $this->stock->update(['quantity_total' => 10, 'average_cost' => 5, 'last_cost' => 6]);
        $entry = $this->move('entry', 10, 15);
        $this->assertEquals(10, $this->stock->fresh()->average_cost);
        $this->assertRebuildMatches();

        $this->assertTrue(app(StockMovementService::class)->delete($entry));
        $this->assertEquals(10, $this->stock->fresh()->quantity_total);
        $this->assertEquals(5, $this->stock->fresh()->average_cost);
        $this->assertEquals(6, $this->stock->fresh()->last_cost);
        $this->assertRebuildMatches();
    }

    public function test_edit_and_delete_reconstruct_the_remaining_chronological_history(): void
    {
        $this->move('entry', 10, 10);
        $exit = $this->move('exit', 5, 30);
        $this->move('entry', 10, 20);
        $service = app(StockMovementService::class);
        $updated = $service->update($exit, [
            ...$this->data('exit', 10, 30),
        ], $this->user->id);
        $this->assertNotNull($updated);
        $this->assertEquals(20, $this->stock->fresh()->average_cost);

        $this->assertTrue($service->delete($exit));
        $this->assertEquals(15, $this->stock->fresh()->average_cost);
        $this->assertEquals(20, $this->stock->fresh()->quantity_total);
        $this->assertRebuildMatches();
    }

    public function test_existing_history_without_an_opening_snapshot_uses_chronological_costs(): void
    {
        $this->move('entry', 10, 10);
        $this->move('exit', 10, 30);
        $this->move('entry', 10, 20);
        $this->stock->fresh()->update(['additional_info' => null]);

        $this->assertRebuildMatches();
    }

    public function test_reconciliation_repairs_stored_cost_and_is_idempotent(): void
    {
        $this->move('entry', 10, 10);
        $this->move('exit', 10, 30);
        $this->move('entry', 10, 20);
        $this->stock->fresh()->update(['average_cost' => 15]);

        $this->artisan('stock:reconcile', ['--stock' => $this->stock->id])->assertSuccessful();
        $this->assertEquals(15, $this->stock->fresh()->average_cost);
        $this->artisan('stock:reconcile', ['--stock' => $this->stock->id, '--fix' => true])->assertSuccessful();
        $this->assertEquals(20, $this->stock->fresh()->average_cost);
        $this->assertRebuildMatches();
    }

    public function test_deleting_the_last_legacy_movement_clears_its_balance_and_cost(): void
    {
        foreach ([false, true] as $force) {
            $entry = $this->move('entry', 10, 10);
            $this->stock->fresh()->update(['additional_info' => null]);
            $service = app(StockMovementService::class);
            $this->assertTrue($force ? $service->forceDelete($entry) : $service->delete($entry));
            $this->assertEquals(0, $this->stock->fresh()->quantity_total);
            $this->assertEquals(0, $this->stock->fresh()->average_cost);
            $this->assertNull($this->stock->fresh()->last_cost);
            $this->assertRebuildMatches();
        }
    }

    private function data(string $type, float $quantity, float $price = 0, string $unit = 'UN'): array
    {
        return ['product_stock_id' => $this->stock->id, 'product_id' => $this->product->id,
            'company_id' => $this->stock->company_id, 'type' => $type, 'quantity' => $quantity,
            'unit_price' => $price, 'operational_unit' => $unit, 'source_type' => 'manual', 'source_id' => 0];
    }

    private function move(string $type, float $quantity, float $price = 0, string $unit = 'UN'): StockMovement
    {
        $service = app(StockMovementService::class);
        $movement = $service->create($this->data($type, $quantity, $price, $unit), $this->user->id);
        $this->assertNotNull($movement, $service->getMessage());

        return $movement;
    }

    private function assertRebuildMatches(): void
    {
        $before = app(CalculateStockMovementStateAction::class)->fromStock($this->stock->fresh());
        $calculator = app(CalculateProductStockSnapshotAction::class);
        $this->assertEquals($before, $calculator->calculate($this->stock->fresh()));
        app(RecalculateProductStockFromMovementsAction::class)->recalculate($this->stock->fresh());
        $this->assertEquals($before, app(CalculateStockMovementStateAction::class)->fromStock($this->stock->fresh()));
        $this->assertSame([], $calculator->diff($this->stock->fresh(), $calculator->calculate($this->stock->fresh())));
    }
}
