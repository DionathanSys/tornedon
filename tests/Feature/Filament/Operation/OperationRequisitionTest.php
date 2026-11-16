<?php

namespace Tests\Feature\Filament\Operation;

use App\Enum\Payment\Condition;
use App\Enum\Payment\Method;
use App\Enum\Product\OriginSalePrice;
use App\Enum\Product\Unit;
use App\Enum\Requisition\Status;
use App\Enum\ServiceOrder\State;
use App\Filament\Operation\Pages\Requisitions\RequisitionDetail;
use App\Models\Company;
use App\Models\CompanyPartner;
use App\Models\Equipment;
use App\Models\Partner;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Requisition;
use App\Models\ServiceOrder;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OperationRequisitionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private Requisition $requisition;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->company = Company::factory()->create(['created_by' => $this->user->id]);
        $this->user->companies()->attach($this->company, ['role' => 'admin', 'is_active' => true]);
        $this->actingAs($this->user);
        Filament::setCurrentPanel('operation');
        Filament::setTenant($this->company);
        $customer = Partner::factory()->create(['created_by' => $this->user->id]);
        CompanyPartner::create(['company_id' => $this->company->id, 'partner_id' => $customer->id, 'type' => ['customer'], 'is_active' => true]);
        $this->requisition = Requisition::create([
            'number' => 'REQ-OPERATION', 'company_id' => $this->company->id, 'customer_id' => $customer->id,
            'sale_date' => today(), 'delivery_date' => today(), 'status' => Status::OPEN, 'created_by' => $this->user->id,
            'payment_method' => Method::PIX, 'payment_condition' => Condition::CASH,
        ]);
    }

    public function test_products_can_be_added_edited_and_deleted_without_losing_unsaved_details(): void
    {
        $stock = $this->stock();
        $page = $this->page()->assertSee('Adicionar produto')
            ->set('formData.observations', 'Observação ainda não salva')
            ->call('openAddProduct')->set('productData.product_stock_id', $stock->id)
            ->assertSet('productData.unit_of_measure', 'UN')->assertSet('productData.unit_price', 50)
            ->set('productData.quantity', '2,000')->set('productData.discount_percentage', 10)
            ->call('saveProduct')->assertHasNoErrors()->assertSet('showProductModal', false)
            ->assertSet('requisition.total', 'R$ 90,00')->assertSet('formData.observations', 'Observação ainda não salva');
        $item = $this->requisition->items()->sole();
        $this->assertSame(2.0, (float) $stock->fresh()->quantity_reserved);
        $this->assertNull($this->requisition->fresh()->observations);

        $page->call('openEditProduct', $item->id)->set('productData.quantity', '3,000')
            ->set('productData.observations', 'Produto substituído')->call('saveProduct')->assertHasNoErrors()
            ->assertSet('requisition.total', 'R$ 135,00')->assertSee('Produto substituído');
        $this->assertSame(3.0, (float) $stock->fresh()->quantity_reserved);
        $page->call('deleteProduct', $item->id)->assertSet('requisition.total', 'R$ 0,00')
            ->assertSet('formData.observations', 'Observação ainda não salva');
        $this->assertDatabaseMissing('requisition_items', ['id' => $item->id]);
        $this->assertSame(0.0, (float) $stock->fresh()->quantity_reserved);
    }

    public function test_details_and_custom_payment_terms_are_saved_and_validated(): void
    {
        $equipment = Equipment::create(['company_id' => $this->company->id, 'owner_id' => $this->requisition->customer_id, 'name' => 'Equipamento do cliente', 'created_by' => $this->user->id]);
        $page = $this->page()->set('formData.equipment_id', $equipment->id)
            ->set('formData.salesperson_id', $this->user->id)->set('formData.payment_method', Method::PIX->value)
            ->set('formData.payment_condition', Condition::CUSTOM->value)
            ->set('formData.payment_condition_details', ['installments' => 3, 'first_due_days' => 10, 'interval_days' => 30])
            ->set('formData.delivery_date', today()->addDays(2)->format('Y-m-d'))
            ->set('formData.delivery_address', 'Rua Entrega, 123')->set('formData.observations', 'Entregar à tarde')
            ->call('save')->assertHasNoErrors();
        $record = $this->requisition->fresh();
        $this->assertSame($equipment->id, $record->equipment_id);
        $this->assertSame($this->user->id, $record->salesperson_id);
        $this->assertSame(['installments' => 3, 'first_due_days' => 10, 'interval_days' => 30], $record->payment_condition_details);
        $this->assertSame('Rua Entrega, 123', $record->delivery_address);
        $this->assertSame('Entregar à tarde', $record->observations);
        $page->set('formData.payment_condition_details.installments', 0)->call('save')->assertHasErrors('formData.payment_condition_details.installments')
            ->set('formData.payment_condition', Condition::CASH->value)->call('save')->assertHasNoErrors();
        $this->assertNull($this->requisition->fresh()->payment_condition_details);
        $page->set('formData.delivery_date', today()->subDay()->format('Y-m-d'))->call('save')->assertHasErrors('formData.delivery_date');
    }

    public function test_units_stock_prices_and_foreign_relations_are_validated(): void
    {
        $stock = $this->stock();
        $stock->product->alternativeUnitConversions()->create(['unit' => Unit::CX, 'conversion_factor' => 5]);
        $page = $this->page()->call('openAddProduct')->set('productData.product_stock_id', $stock->id)
            ->set('productData.unit_price', 5)->call('saveProduct')->assertHasErrors('productData.unit_price')
            ->set('productData.unit_price', 50)->set('productData.unit_of_measure', 'KG')->call('saveProduct')->assertHasErrors('productData.unit_of_measure')
            ->set('productData.unit_of_measure', 'CX')->set('productData.quantity', 21)->call('saveProduct')->assertHasErrors('productData.unit_price')
            ->set('productData.quantity', 2)->call('saveProduct')->assertHasNoErrors();
        $this->assertSame(10.0, $this->requisition->items()->sole()->resolvedBaseQuantity());
        $this->assertSame(10.0, (float) $stock->fresh()->quantity_reserved);

        $foreignStock = $this->stock(Company::factory()->create());
        $page->call('openAddProduct')->set('productData.product_stock_id', $foreignStock->id)
            ->call('saveProduct')->assertHasErrors('productData.product_stock_id');
        $foreignUser = User::factory()->create();
        $foreignEquipment = Equipment::create(['company_id' => $this->company->id, 'owner_id' => Partner::factory()->create()->id, 'name' => 'Outro cliente', 'created_by' => $this->user->id]);
        $page->set('formData.salesperson_id', $foreignUser->id)->set('formData.equipment_id', $foreignEquipment->id)
            ->call('save')->assertHasErrors(['formData.salesperson_id', 'formData.equipment_id']);
    }

    public function test_close_reopen_pdf_and_invoice_use_the_existing_business_flow(): void
    {
        $stock = $this->stock();
        $page = $this->page()->call('close')->assertHasNoErrors()->assertSet('requisition.status_value', 'open')
            ->call('openAddProduct')->set('productData.product_stock_id', $stock->id)->set('productData.quantity', 2)
            ->call('saveProduct')->assertHasNoErrors()
            ->set('formData.observations', 'Salvo ao encerrar')->call('close')->assertHasNoErrors()
            ->assertSet('requisition.status_value', 'closed')->assertSet('requisition.can_edit', false)
            ->call('downloadPdf')->assertFileDownloaded('requisicao-REQ-OPERATION.pdf');
        $this->assertSame('Salvo ao encerrar', $this->requisition->fresh()->observations);
        $this->assertSame(100.0, (float) $stock->fresh()->quantity_total);
        $page->call('reopen')->assertSet('requisition.status_value', 'open')->assertSet('requisition.can_edit', true)
            ->call('close')->assertHasNoErrors()->call('invoiceRequisition')->assertHasNoErrors()
            ->assertSet('requisition.status_value', 'invoiced');
        $this->assertNotNull($this->requisition->fresh()->invoice_id);
        $this->assertSame(100.0, (float) $stock->fresh()->quantity_total);
    }

    public static function readOnlyStates(): array
    {
        return [[Status::CLOSED], [Status::CANCELLED], [Status::INVOICED]];
    }

    #[DataProvider('readOnlyStates')]
    public function test_non_editable_requisitions_reject_product_and_details_changes(Status $status): void
    {
        $this->requisition->update(['status' => $status]);
        foreach (['openAddProduct', 'openEditProduct', 'saveProduct', 'deleteProduct', 'save'] as $method) {
            $this->page()->assertSet('requisition.can_edit', false)->call($method, 1)->assertForbidden();
        }
    }

    public function test_items_and_requisitions_are_scoped_and_linked_orders_control_editing(): void
    {
        $stock = $this->stock();
        $this->page()->call('openAddProduct')->set('productData.product_stock_id', $stock->id)->call('saveProduct')->assertHasNoErrors();
        $item = $this->requisition->items()->sole();
        $other = $this->requisition->replicate();
        $other->number = 'OUTRA-REQ';
        $other->save();
        foreach (['openEditProduct', 'deleteProduct'] as $method) {
            Livewire::test(RequisitionDetail::class, ['record' => $other->id])->call($method, $item->id)->assertNotFound();
        }

        $order = ServiceOrder::factory()->create(['company_id' => $this->company->id, 'customer_id' => $this->requisition->customer_id, 'created_by' => $this->user->id, 'status' => State::OPEN]);
        $this->requisition->update(['service_order_id' => $order->id]);
        $page = $this->page()->assertSet('requisition.can_edit', true);
        $actions = collect($page->instance()->getFooter()->getData()['actions']);
        $this->assertFalse($actions->contains('method', 'close'));
        $page->call('close')->assertForbidden();
        $order->update(['status' => State::CLOSED]);
        $this->page()->assertSet('requisition.can_edit', false)->call('openAddProduct')->assertForbidden();

        $this->requisition->update(['company_id' => Company::factory()->create()->id]);
        foreach (['openAddProduct', 'saveProduct', 'save', 'downloadPdf', 'reopen', 'invoiceRequisition'] as $method) {
            $this->page()->call($method)->assertNotFound();
        }
    }

    private function page(): Testable
    {
        return Livewire::test(RequisitionDetail::class, ['record' => $this->requisition->id]);
    }

    private function stock(?Company $company = null): ProductStock
    {
        $product = Product::factory()->create([
            'company_id' => ($company ?? $this->company)->id, 'created_by' => $this->user->id,
            'name' => 'Produto da requisição', 'unit' => Unit::UN, 'is_active' => true, 'is_invoiceable' => true,
            'has_stock_control' => true, 'origin_sale_price' => OriginSalePrice::FIXED, 'sale_price_value' => 50, 'min_sale_price' => 10,
        ]);

        return ProductStock::create(['product_id' => $product->id, 'company_id' => $product->company_id, 'quantity_total' => 100, 'quantity_reserved' => 0, 'created_by' => $this->user->id]);
    }
}
