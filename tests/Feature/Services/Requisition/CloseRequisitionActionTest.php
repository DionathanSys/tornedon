<?php

namespace Tests\Feature\Services\Requisition;

use App\Enum\Requisition\Status;
use App\Enum\StockMovement\Type;
use App\Models\AuditEntry;
use App\Models\Company;
use App\Models\Partner;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Requisition\Actions\CloseRequisitionAction;
use App\Enum\Product\OriginSalePrice;
use App\Enum\Product\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CloseRequisitionActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_closes_requisition_and_records_audit_entry(): void
    {
        $user = User::factory()->create();

        $company = Company::query()->create([
            'name' => 'Empresa Requisicao',
            'document_number' => '12345678000999',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);

        $customer = Partner::query()->create([
            'name' => 'Cliente Requisicao',
            'document_type' => 'CPF',
            'document_number' => '12345678911',
            'created_by' => $user->id,
        ]);

        $product = Product::query()->create([
            'company_id' => $company->id,
            'created_by' => $user->id,
            'has_stock_control' => false,
            'name' => 'Produto Requisicao',
            'product_code' => 'PRD-REQ-0001',
            'unit' => Unit::UN,
            'origin_sale_price' => OriginSalePrice::FREE,
            'sale_price_value' => 10,
            'is_active' => true,
        ]);

        $requisition = Requisition::query()->create([
            'number' => 'REQ-00023',
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'sale_date' => '2026-04-22',
            'status' => Status::OPEN,
            'stock_reserved' => true,
            'stock_consumed' => false,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'product_id' => $product->id,
            'unit_of_measure' => Unit::UN->value,
            'quantity' => 1,
            'unit_price' => 10,
            'stock_consumed' => false,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $result = (new CloseRequisitionAction($user->id))->execute($requisition);

        $this->assertNotNull($result);
        $this->assertSame(Status::CLOSED, $result->fresh()->status);
        $this->assertDatabaseHas('audit_entries', [
            'company_id' => $company->id,
            'auditable_type' => 'requisition',
            'auditable_id' => $requisition->id,
            'actor_user_id' => $user->id,
            'event' => 'requisition.closed',
            'action' => 'closed',
        ]);
        $this->assertSame(
            1,
            AuditEntry::query()
                ->where('event', 'requisition.closed')
                ->where('auditable_id', $requisition->id)
                ->count()
        );
    }

    public function test_it_does_not_close_requisition_without_items(): void
    {
        $user = User::factory()->create();

        $company = Company::query()->create([
            'name' => 'Empresa Sem Itens',
            'document_number' => '12312312300011',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);

        $customer = Partner::query()->create([
            'name' => 'Cliente Sem Itens',
            'document_type' => 'CPF',
            'document_number' => '12312312399',
            'created_by' => $user->id,
        ]);

        $requisition = Requisition::query()->create([
            'number' => 'REQ-00022',
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'sale_date' => '2026-04-22',
            'status' => Status::OPEN,
            'stock_reserved' => false,
            'stock_consumed' => false,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $action = new CloseRequisitionAction($user->id);
        $result = $action->execute($requisition);

        $this->assertNull($result);
        $this->assertSame('Não é possível encerrar requisição sem itens.', $action->getMessage());
        $this->assertSame(Status::OPEN, $requisition->fresh()->status);
    }

    public function test_it_closes_requisition_when_the_only_available_stock_is_reserved_by_its_own_item(): void
    {
        $user = User::factory()->create();

        $company = Company::query()->create([
            'name' => 'Empresa Reserva',
            'document_number' => '98765432000188',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);

        $customer = Partner::query()->create([
            'name' => 'Cliente Reserva',
            'document_type' => 'CPF',
            'document_number' => '98765432100',
            'created_by' => $user->id,
        ]);

        $product = Product::query()->create([
            'company_id' => $company->id,
            'created_by' => $user->id,
            'has_stock_control' => true,
            'name' => 'Produto Reservado',
            'product_code' => 'PRD-RES-001',
            'unit' => Unit::UN,
            'origin_sale_price' => OriginSalePrice::FREE,
            'sale_price_value' => 10,
            'is_active' => true,
        ]);

        $stock = ProductStock::query()->create([
            'product_id' => $product->id,
            'company_id' => $company->id,
            'quantity_total' => 2,
            'quantity_reserved' => 2,
            'is_active' => true,
            'allow_negative' => false,
            'created_by' => $user->id,
        ]);

        $requisition = Requisition::query()->create([
            'number' => 'REQ-00024',
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'sale_date' => '2026-04-22',
            'status' => Status::OPEN,
            'stock_reserved' => false,
            'stock_consumed' => false,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $item = RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'product_id' => $product->id,
            'unit_of_measure' => 'UN',
            'quantity' => 2,
            'unit_price' => 10,
            'stock_consumed' => false,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        StockMovement::query()->create([
            'product_stock_id' => $stock->id,
            'product_id' => $product->id,
            'company_id' => $company->id,
            'type' => Type::RESERVATION,
            'quantity' => 2,
            'unit_price' => 10,
            'reason' => 'Reserva por item de requisição',
            'source_type' => 'requisition_item',
            'source_id' => $item->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $action = new CloseRequisitionAction($user->id);
        $result = $action->execute($requisition);

        $this->assertNotNull($result);
        $this->assertSame(Status::CLOSED, $result->fresh()->status);
        $this->assertTrue((bool) $result->fresh()->stock_reserved);
    }

    public function test_it_closes_requisition_using_reserved_base_quantity_for_alternative_unit(): void
    {
        $user = User::factory()->create();

        $company = Company::query()->create([
            'name' => 'Empresa Reserva Alternativa',
            'document_number' => '11222333000144',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);

        $customer = Partner::query()->create([
            'name' => 'Cliente Reserva Alternativa',
            'document_type' => 'CPF',
            'document_number' => '11122233344',
            'created_by' => $user->id,
        ]);

        $product = Product::query()->create([
            'company_id' => $company->id,
            'created_by' => $user->id,
            'has_stock_control' => true,
            'name' => 'Produto Jogo',
            'product_code' => 'PRD-ALT-001',
            'unit' => Unit::JG,
            'origin_sale_price' => OriginSalePrice::FREE,
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
            'quantity_total' => 1,
            'quantity_reserved' => 1,
            'is_active' => true,
            'allow_negative' => false,
            'created_by' => $user->id,
        ]);

        $requisition = Requisition::query()->create([
            'number' => 'REQ-00025',
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'sale_date' => '2026-04-22',
            'status' => Status::OPEN,
            'stock_reserved' => false,
            'stock_consumed' => false,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $item = RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'product_id' => $product->id,
            'unit_of_measure' => Unit::PC->value,
            'quantity' => 8,
            'unit_price' => 10,
            'stock_consumed' => false,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        StockMovement::query()->create([
            'product_stock_id' => $stock->id,
            'product_id' => $product->id,
            'company_id' => $company->id,
            'type' => Type::RESERVATION,
            'operational_unit' => Unit::PC->value,
            'operational_quantity' => 8,
            'base_unit' => Unit::JG->value,
            'base_quantity' => 1,
            'conversion_factor_snapshot' => 0.125,
            'quantity' => 1,
            'unit_price' => 10,
            'reason' => 'Reserva por item de requisição',
            'source_type' => 'requisition_item',
            'source_id' => $item->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $action = new CloseRequisitionAction($user->id);
        $result = $action->execute($requisition);

        $this->assertNotNull($result);
        $this->assertSame(Status::CLOSED, $result->fresh()->status);
        $this->assertTrue((bool) $result->fresh()->stock_reserved);
    }
}
