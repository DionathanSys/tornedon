<?php

namespace Tests\Feature\Services\RequisitionItem;

use App\Enum\Product\OriginSalePrice;
use App\Enum\Product\Unit;
use App\Enum\Requisition\Status;
use App\Events\RequisitionItem\RequisitionItemCreated;
use App\Events\RequisitionItem\RequisitionItemUpdated;
use App\Models\Company;
use App\Models\Partner;
use App\Models\Product;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\User;
use App\Services\RequisitionItem\Actions\CreateRequisitionItemAction;
use App\Services\RequisitionItem\Actions\UpdateRequisitionItemAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class RequisitionItemPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_persists_base_quantity_and_conversion_snapshot_when_creating_item(): void
    {
        Event::fake([RequisitionItemCreated::class]);

        [$user, $product, $requisition] = $this->makeContext();

        $product->alternativeUnitConversions()->create([
            'unit' => Unit::PC->value,
            'conversion_factor' => 0.125,
        ]);

        $action = new CreateRequisitionItemAction($user->id);

        $item = $action->execute([
            'requisition_id' => $requisition->id,
            'product_id' => $product->id,
            'unit_of_measure' => Unit::PC->value,
            'quantity' => 16,
            'unit_price' => 5,
        ]);

        $this->assertNotNull($item, $action->getMessage() ?? 'Falha ao criar item de requisição.');
        $this->assertEquals(2.0, (float) $item->quantity_in_base_unit);
        $this->assertEquals(0.125, (float) $item->conversion_factor_snapshot);
    }

    public function test_it_updates_base_quantity_and_conversion_snapshot_when_item_changes(): void
    {
        Event::fake([RequisitionItemUpdated::class]);

        [$user, $product, $requisition] = $this->makeContext();

        $product->alternativeUnitConversions()->create([
            'unit' => Unit::PC->value,
            'conversion_factor' => 0.125,
        ]);

        $item = RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'product_id' => $product->id,
            'unit_of_measure' => Unit::JG->value,
            'quantity' => 1,
            'quantity_in_base_unit' => 1,
            'conversion_factor_snapshot' => 1,
            'unit_price' => 10,
            'stock_consumed' => false,
            'created_by' => $user->id,
        ]);

        $action = new UpdateRequisitionItemAction($user->id, $item);

        $updated = $action->execute([
            'unit_of_measure' => Unit::PC->value,
            'quantity' => 8,
        ]);

        $this->assertNotNull($updated, $action->getMessage() ?? 'Falha ao atualizar item de requisição.');
        $this->assertSame(Unit::PC->value, $updated->unit_of_measure);
        $this->assertEquals(1.0, (float) $updated->quantity_in_base_unit);
        $this->assertEquals(0.125, (float) $updated->conversion_factor_snapshot);
    }

    public function test_it_clears_stock_consumed_at_when_stock_consumed_is_false(): void
    {
        [$user, $product, $requisition] = $this->makeContext();

        $item = RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'product_id' => $product->id,
            'unit_of_measure' => Unit::JG->value,
            'quantity' => 1,
            'quantity_in_base_unit' => 1,
            'conversion_factor_snapshot' => 1,
            'unit_price' => 10,
            'stock_consumed' => false,
            'stock_consumed_at' => now(),
            'created_by' => $user->id,
        ]);

        $this->assertNull($item->fresh()->stock_consumed_at);
    }

    private function makeContext(): array
    {
        $user = User::factory()->create();

        $company = Company::query()->create([
            'name' => 'Empresa Item Requisicao',
            'document_number' => '99888777000166',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);

        $customer = Partner::query()->create([
            'name' => 'Cliente Item Requisicao',
            'document_type' => 'CPF',
            'document_number' => '12345678901',
            'created_by' => $user->id,
        ]);

        $product = Product::query()->create([
            'company_id' => $company->id,
            'created_by' => $user->id,
            'has_stock_control' => false,
            'name' => 'Produto Conversao Item',
            'product_code' => 'PRD-REQ-001',
            'unit' => Unit::JG->value,
            'origin_sale_price' => OriginSalePrice::FREE->value,
            'sale_price_value' => 10,
            'is_active' => true,
        ]);

        $requisition = Requisition::query()->create([
            'number' => 'REQ-PERSIST-001',
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'sale_date' => now()->toDateString(),
            'status' => Status::OPEN,
            'stock_reserved' => false,
            'stock_consumed' => false,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        return [$user, $product, $requisition];
    }
}
