<?php

namespace Tests\Feature\Filament;

use App\Filament\Clusters\Inventory\Resources\Products\Pages\CreateProduct;
use App\Filament\Clusters\Inventory\Resources\Products\Pages\EditProduct;
use App\Filament\Clusters\Inventory\Resources\Products\RelationManagers\InventoryHistoryRelationManager;
use App\Filament\Clusters\Inventory\Resources\Products\RelationManagers\ReservationsRelationManager;
use App\Filament\Clusters\Inventory\Resources\Products\RelationManagers\StockMovementsRelationManager;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductInventoryTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private ProductStock $stock;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Empresa', 'address' => ['city' => 'São Paulo', 'state' => 'SP'], 'document_number' => '12345678000190', 'is_active' => true, 'created_by' => $user->id]);
        $user->companies()->attach($company, ['role' => 'owner', 'is_active' => true]);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($company);
        Filament::bootCurrentPanel();
        $this->product = Product::create(['company_id' => $company->id, 'created_by' => $user->id, 'name' => 'Produto teste',
            'product_code' => 'TEST-INV', 'unit' => 'UN', 'sale_unit' => 'UN', 'origin_sale_price' => 'calculated',
            'profit_margin' => 40, 'min_sale_price' => 0, 'has_stock_control' => true, 'is_active' => true]);
        $this->stock = ProductStock::create(['product_id' => $this->product->id, 'company_id' => $company->id,
            'quantity_total' => 10, 'quantity_reserved' => 0, 'quantity_minimum' => 0, 'average_cost' => 5,
            'last_cost' => 5, 'is_active' => true, 'allow_negative' => false]);
    }

    public function test_product_renders_inventory_and_saves_negative_configuration(): void
    {
        Livewire::test(EditProduct::class, ['record' => $this->product->id])
            ->assertSuccessful()->assertSee('Custos e preços')->assertSee('Saldo total')
            ->fillForm(['stock' => ['allow_negative' => true, 'quantity_minimum' => 2, 'quantity_maximum' => 20]])
            ->call('save')->assertHasNoFormErrors();
        $this->assertTrue($this->stock->fresh()->allow_negative);
        $this->assertEquals(2, $this->stock->fresh()->quantity_minimum);
    }

    public function test_disabling_control_with_balance_rolls_back_product_update(): void
    {
        Livewire::test(EditProduct::class, ['record' => $this->product->id])
            ->fillForm(['name' => 'Não salvar', 'has_stock_control' => false])
            ->call('save')->assertHasFormErrors(['has_stock_control']);
        $this->assertSame('Produto teste', $this->product->fresh()->name);
        $this->assertTrue($this->product->fresh()->has_stock_control);
    }

    public function test_inventory_relation_managers_render(): void
    {
        foreach ([StockMovementsRelationManager::class, ReservationsRelationManager::class, InventoryHistoryRelationManager::class] as $manager) {
            Livewire::test($manager, ['ownerRecord' => $this->product, 'pageClass' => EditProduct::class])->assertSuccessful();
        }
    }

    public function test_entry_modal_updates_stock_and_costs(): void
    {
        Livewire::test(StockMovementsRelationManager::class, ['ownerRecord' => $this->product, 'pageClass' => EditProduct::class])
            ->callAction(TestAction::make('productMovement')->table(), data: ['type' => 'entry', 'operational_unit' => 'UN', 'quantity' => 10, 'unit_price' => 10, 'reason' => 'Compra'])
            ->assertHasNoActionErrors();
        $this->assertEquals(20, $this->stock->fresh()->quantity_total);
        $this->assertEquals(7.5, $this->stock->fresh()->average_cost);
    }

    public function test_admin_adjustment_modal_records_reason_and_refreshes_costs(): void
    {
        Livewire::test(EditProduct::class, ['record' => $this->product->id])
            ->callAction('adjustProductCosts', data: ['average_cost' => 12.3456, 'last_cost' => 15, 'reason' => 'Correção administrativa'])
            ->assertHasNoActionErrors();
        $this->assertEquals(12.3456, $this->stock->fresh()->average_cost);
        $this->assertDatabaseHas('audit_entries', ['event' => 'product.costs.updated']);
    }

    public function test_regular_member_does_not_see_cost_adjustment(): void
    {
        auth()->user()->companies()->updateExistingPivot($this->product->company_id, ['role' => 'member']);
        Livewire::test(EditProduct::class, ['record' => $this->product->id])->assertActionHidden('adjustProductCosts');
    }

    public function test_reservations_show_net_balance_by_source(): void
    {
        $base = ['product_id' => $this->product->id, 'product_stock_id' => $this->stock->id, 'company_id' => $this->product->company_id,
            'source_type' => 'manual', 'source_id' => 0, 'unit_price' => 0, 'created_by' => auth()->id()];
        $reservation = StockMovement::create([...$base, 'type' => 'reservation', 'quantity' => 8]);
        StockMovement::create([...$base, 'type' => 'reservation_release', 'quantity' => 3]);
        Livewire::test(ReservationsRelationManager::class, ['ownerRecord' => $this->product, 'pageClass' => EditProduct::class])
            ->assertCanSeeTableRecords([$reservation])->assertTableColumnStateSet('reserved_quantity', 5, $reservation);
    }

    public function test_enabling_stock_creates_and_configures_it_in_same_save(): void
    {
        $this->stock->delete();
        $this->product->update(['has_stock_control' => false]);
        Livewire::test(EditProduct::class, ['record' => $this->product->id])
            ->fillForm(['has_stock_control' => true, 'stock' => ['allow_negative' => true, 'quantity_minimum' => 4, 'quantity_maximum' => 40]])
            ->call('save')->assertHasNoFormErrors();
        $this->assertTrue($this->product->fresh()->has_stock_control);
        $this->assertTrue($this->stock->fresh()->allow_negative);
        $this->assertEquals(4, $this->stock->fresh()->quantity_minimum);
    }

    public function test_create_preserves_fixed_price_and_initial_stock_configuration(): void
    {
        Livewire::test(CreateProduct::class)
            ->fillForm(['name' => 'Novo produto', 'unit' => 'UN', 'sale_unit' => 'UN', 'origin_sale_price' => 'fixed',
                'sale_price_value' => '42,00', 'has_stock_control' => true,
                'stock' => ['allow_negative' => true, 'quantity_minimum' => 2, 'quantity_maximum' => 20]])
            ->call('create')->assertHasNoFormErrors();
        $product = Product::where('name', 'Novo produto')->firstOrFail();
        $this->assertEquals(42, $product->sale_price_value);
        $this->assertTrue($product->stock->allow_negative);
        $this->assertEquals(2, $product->stock->quantity_minimum);
    }
}
