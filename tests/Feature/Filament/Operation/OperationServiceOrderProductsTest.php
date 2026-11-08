<?php

namespace Tests\Feature\Filament\Operation;

use App\Enum\Product\OriginSalePrice;
use App\Enum\Product\Unit;
use App\Enum\Requisition\Status;
use App\Enum\ServiceOrder\Priority;
use App\Enum\ServiceOrder\State;
use App\Enum\ServiceOrder\Type;
use App\Filament\Operation\Pages\ServiceOrders\ServiceOrderDetail;
use App\Models\Company;
use App\Models\CompanyPartner;
use App\Models\Partner;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OperationServiceOrderProductsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private ServiceOrder $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->company = Company::factory()->create(['created_by' => $this->user->id]);
        $this->user->companies()->attach($this->company, ['role' => 'admin', 'is_active' => true]);
        $this->actingAs($this->user);
        Filament::setCurrentPanel('operation');
        Filament::setTenant($this->company);
        $customer = Partner::create(['name' => 'Cliente produtos', 'document_type' => 'cpf', 'document_number' => '52998224725', 'created_by' => $this->user->id]);
        CompanyPartner::create(['company_id' => $this->company->id, 'partner_id' => $customer->id, 'type' => ['customer'], 'is_active' => true]);
        $this->order = ServiceOrder::create(['number' => 'OS-PRODUTOS', 'company_id' => $this->company->id, 'customer_id' => $customer->id, 'created_by' => $this->user->id, 'order_date' => now(), 'status' => State::OPEN, 'priority' => Priority::NORMAL, 'type' => Type::MAINTENANCE]);
    }

    public function test_products_create_and_reuse_requisition_update_totals_and_preserve_unsaved_form(): void
    {
        $stock = $this->stock();
        $page = Livewire::test(ServiceOrderDetail::class, ['record' => $this->order->id])
            ->set('formData.solution', 'Texto ainda não salvo')
            ->call('openAddProduct')->set('productData.product_stock_id', $stock->id)
            ->assertSet('productData.unit_price', 50)->set('productData.quantity', '2,000')
            ->set('productData.discount_percentage', 10)->call('saveProduct')->assertHasNoErrors()
            ->assertSet('showProductModal', false)->assertSet('order.products_total', 'R$ 90,00')
            ->assertSet('order.total', 'R$ 90,00')->assertSet('formData.solution', 'Texto ainda não salvo')
            ->assertSee('Peça de teste');
        $requisition = $this->order->requisition()->sole();
        $this->assertSame($this->company->id, $requisition->company_id);
        $this->assertSame($this->order->customer_id, $requisition->customer_id);
        $this->assertSame(2.0, (float) $stock->fresh()->quantity_reserved);
        $page->call('openAddProduct')->set('productData.product_stock_id', $stock->id)->call('saveProduct')->assertHasNoErrors();
        $this->assertDatabaseCount('requisitions', 1);
        $this->assertCount(2, $requisition->items()->get());
        $item = $requisition->items()->first();
        $page->call('openEditProduct', $item->id)->set('productData.quantity', 3)
            ->set('productData.observations', 'Peça substituída')->call('saveProduct')->assertHasNoErrors()
            ->assertSet('order.total', 'R$ 185,00')->assertSee('Peça substituída');
        $this->assertSame(4.0, (float) $stock->fresh()->quantity_reserved);
        $page->call('deleteProduct', $item->id)->assertSet('order.total', 'R$ 50,00')
            ->assertSet('formData.solution', 'Texto ainda não salvo');
        $this->assertSame(1.0, (float) $stock->fresh()->quantity_reserved);
        $this->assertDatabaseMissing('requisition_items', ['id' => $item->id]);
    }

    public function test_units_are_converted_and_closing_preserves_stock_reservations(): void
    {
        $stock = $this->stock();
        $service = Service::factory()->create(['company_id' => $this->company->id, 'created_by' => $this->user->id]);
        $this->order->items()->create(['service_id' => $service->id, 'quantity' => 1, 'unit_price' => 100, 'created_by' => $this->user->id]);
        $stock->product->alternativeUnitConversions()->create(['unit' => Unit::CX, 'conversion_factor' => 5]);
        Livewire::test(ServiceOrderDetail::class, ['record' => $this->order->id])
            ->call('openAddProduct')->set('productData.product_stock_id', $stock->id)
            ->set('productData.unit_of_measure', 'CX')->set('productData.quantity', 2)
            ->call('saveProduct')->assertHasNoErrors()
            ->call('close')->assertHasNoErrors();
        $item = $this->order->requisition()->sole()->items()->sole();
        $this->assertSame(10.0, $item->resolvedBaseQuantity());
        $this->assertFalse($item->stock_consumed);
        $this->assertSame(State::CLOSED, $this->order->fresh()->status);
        $this->assertSame(Status::CLOSED, $this->order->requisition()->sole()->status);
        $this->assertSame(100.0, (float) $stock->fresh()->quantity_total);
        $this->assertSame(10.0, (float) $stock->fresh()->quantity_reserved);
    }

    public function test_invalid_price_stock_and_unit_do_not_leave_an_empty_requisition(): void
    {
        $stock = $this->stock();
        $page = Livewire::test(ServiceOrderDetail::class, ['record' => $this->order->id])
            ->call('openAddProduct')->set('productData.product_stock_id', $stock->id)
            ->set('productData.unit_price', 5)->call('saveProduct')->assertHasErrors('productData.unit_price');
        $this->assertDatabaseCount('requisitions', 0);
        $page->set('productData.unit_price', 50)->set('productData.quantity', 101)->call('saveProduct')->assertHasErrors('productData.unit_price');
        $this->assertDatabaseCount('requisitions', 0);
        $page->set('productData.quantity', 1)->set('productData.unit_of_measure', 'CX')->call('saveProduct')->assertHasErrors('productData.unit_of_measure');
        $this->assertDatabaseCount('requisition_items', 0);
    }

    public function test_search_and_save_reject_foreign_inactive_and_non_invoiceable_products(): void
    {
        $foreign = $this->stock(Company::factory()->create());
        $inactive = $this->stock();
        $inactive->product->update(['is_active' => false]);
        $notInvoiceable = $this->stock();
        $notInvoiceable->product->update(['is_invoiceable' => false]);
        foreach ([$foreign, $inactive, $notInvoiceable] as $stock) {
            Livewire::test(ServiceOrderDetail::class, ['record' => $this->order->id])
                ->call('openAddProduct')->assertSet('products', [])
                ->set('productData.product_stock_id', $stock->id)->set('productData.unit_of_measure', 'UN')
                ->set('productData.unit_price', 50)->call('saveProduct')->assertHasErrors();
        }
        $this->assertDatabaseCount('requisitions', 0);
    }

    public function test_item_actions_reject_items_from_another_order_and_closed_requisitions(): void
    {
        $stock = $this->stock();
        $page = Livewire::test(ServiceOrderDetail::class, ['record' => $this->order->id])
            ->call('openAddProduct')->set('productData.product_stock_id', $stock->id)->call('saveProduct')->assertHasNoErrors();
        $requisition = $this->order->requisition()->sole();
        $item = $requisition->items()->sole();
        $otherOrder = $this->order->replicate();
        $otherOrder->number = 'OUTRA-OS';
        $otherOrder->save();
        foreach (['openEditProduct', 'deleteProduct'] as $method) {
            Livewire::test(ServiceOrderDetail::class, ['record' => $otherOrder->id])->call($method, $item->id)->assertNotFound();
        }
        $requisition->update(['status' => Status::CLOSED]);
        $page->call('saveProduct')->assertForbidden();
        Livewire::test(ServiceOrderDetail::class, ['record' => $this->order->id])->assertSee('Peça de teste')
            ->assertSet('order.can_edit_products', false)->call('deleteProduct', $item->id)->assertForbidden();
    }

    public static function nonEditableStates(): array
    {
        return [[State::CLOSED], [State::INVOICED], [State::CANCELLED]];
    }

    #[DataProvider('nonEditableStates')]
    public function test_non_editable_orders_reject_product_changes(State $state): void
    {
        $this->order->update(['status' => $state]);
        foreach (['openAddProduct', 'saveProduct', 'openEditProduct', 'deleteProduct'] as $method) {
            Livewire::test(ServiceOrderDetail::class, ['record' => $this->order->id])->call($method, 1)->assertForbidden();
        }
    }

    public function test_product_actions_reject_foreign_orders(): void
    {
        $this->order->update(['company_id' => Company::factory()->create()->id]);
        foreach (['openAddProduct', 'saveProduct', 'openEditProduct', 'deleteProduct'] as $method) {
            Livewire::test(ServiceOrderDetail::class, ['record' => $this->order->id])->call($method, 1)->assertNotFound();
        }
    }

    private function stock(?Company $company = null): ProductStock
    {
        $product = Product::factory()->create(['company_id' => ($company ?? $this->company)->id, 'created_by' => $this->user->id, 'name' => 'Peça de teste', 'unit' => Unit::UN, 'is_invoiceable' => true, 'has_stock_control' => true, 'origin_sale_price' => OriginSalePrice::FIXED, 'sale_price_value' => 50, 'min_sale_price' => 10]);

        return ProductStock::create(['product_id' => $product->id, 'company_id' => $product->company_id, 'quantity_total' => 100, 'quantity_reserved' => 0, 'created_by' => $this->user->id]);
    }
}
