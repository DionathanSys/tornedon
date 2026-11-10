<?php

namespace Tests\Feature\Services\Product;

use App\Models\AuditEntry;
use App\Models\Company;
use App\Models\CompanyRole;
use App\Models\CompanyRoleAssignment;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\User;
use App\Services\Product\ProductInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ProductInventoryServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private Product $product;

    private ProductStock $stock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->company = Company::create(['name' => 'Empresa', 'address' => ['city' => 'São Paulo', 'state' => 'SP'], 'document_number' => '12345678000190', 'is_active' => true, 'created_by' => $this->user->id]);
        $this->user->companies()->attach($this->company, ['role' => 'owner', 'is_active' => true]);
        $this->actingAs($this->user);
        $this->product = Product::create(['company_id' => $this->company->id, 'created_by' => $this->user->id,
            'name' => 'Produto', 'product_code' => 'INV-01', 'unit' => 'UN', 'origin_sale_price' => 'calculated',
            'has_stock_control' => true, 'is_active' => true]);
        $this->stock = ProductStock::create(['product_id' => $this->product->id, 'company_id' => $this->company->id,
            'quantity_total' => 10, 'quantity_reserved' => 0, 'average_cost' => 5, 'last_cost' => 5,
            'allow_negative' => false, 'is_active' => true]);
    }

    public function test_configuration_is_whitelisted_and_audited(): void
    {
        app(ProductInventoryService::class)->saveConfiguration($this->product, [
            'allow_negative' => true, 'quantity_minimum' => 2, 'quantity_maximum' => 20,
            'quantity_total' => 1000, 'average_cost' => 999, 'company_id' => 999,
        ], $this->user, $this->company);

        $stock = $this->stock->fresh();
        $this->assertTrue($stock->allow_negative);
        $this->assertEquals(2, $stock->quantity_minimum);
        $this->assertEquals(10, $stock->quantity_total);
        $this->assertEquals(5, $stock->average_cost);
        $this->assertDatabaseHas('audit_entries', ['event' => 'product.inventory.updated', 'auditable_id' => $this->product->id]);
    }

    public function test_owner_can_adjust_costs_with_before_after_and_reason(): void
    {
        app(ProductInventoryService::class)->adjustCosts($this->product, ['average_cost' => 7.1234, 'last_cost' => 8.5678, 'reason' => 'Correção de implantação'], $this->user, $this->company);
        $this->assertEquals(7.1234, $this->stock->fresh()->average_cost);
        $entry = AuditEntry::where('event', 'product.costs.updated')->firstOrFail();
        $this->assertEquals(5, $entry->diff['average_cost']['before']);
        $this->assertEquals(7.1234, $entry->diff['average_cost']['after']);
        $this->assertSame('Correção de implantação', $entry->metadata['reason']);
    }

    public function test_regular_member_cannot_adjust_costs_even_if_platform_admin(): void
    {
        $this->user->update(['is_admin' => true]);
        $this->user->companies()->updateExistingPivot($this->company->id, ['role' => 'member']);
        $this->expectException(HttpException::class);
        app(ProductInventoryService::class)->adjustCosts($this->product, ['average_cost' => 7, 'last_cost' => 8, 'reason' => 'Teste'], $this->user, $this->company);
    }

    public function test_operations_feed_costs_and_ignore_forged_identifiers(): void
    {
        $this->product->alternativeUnitConversions()->create(['unit' => 'CX', 'conversion_factor' => 2]);
        $movement = app(ProductInventoryService::class)->move($this->product, [
            'type' => 'entry', 'quantity' => 5, 'operational_unit' => 'CX', 'unit_price' => 20, 'reason' => 'Compra',
            'company_id' => 999, 'product_id' => 999, 'product_stock_id' => 999,
        ], $this->user, $this->company);
        $this->assertEquals(20, $this->stock->fresh()->quantity_total);
        $this->assertEquals(7.5, $this->stock->fresh()->average_cost);
        $this->assertEquals(10, $this->stock->fresh()->last_cost);
        $this->assertEquals($this->company->id, $movement->company_id);
        $this->assertEquals($this->product->id, $movement->product_id);
        $this->assertEquals(0, $movement->source_id);
    }

    public function test_failed_exit_leaves_no_movement_or_balance_change(): void
    {
        try {
            app(ProductInventoryService::class)->move($this->product, [
                'type' => 'exit', 'quantity' => 11, 'operational_unit' => 'UN', 'unit_price' => 5, 'reason' => 'Saída',
            ], $this->user, $this->company);
            $this->fail('A saída deve falhar.');
        } catch (ValidationException) {
            $this->assertEquals(10, $this->stock->fresh()->quantity_total);
            $this->assertDatabaseCount('stock_movements', 0);
        }
    }

    public function test_count_records_difference_and_preserves_cost_for_outbound(): void
    {
        $movement = app(ProductInventoryService::class)->move($this->product, ['counted_quantity' => 7, 'reason' => 'Inventário'], $this->user, $this->company, count: true);
        $this->assertEquals(7, $this->stock->fresh()->quantity_total);
        $this->assertEquals(3, $movement->quantity);
        $this->assertEquals(5, $this->stock->fresh()->average_cost);
        $this->assertSame('inventory_count', $movement->additional_info['operation']);
        $this->assertEquals(10, $movement->additional_info['previous_quantity']);
    }

    public function test_count_cannot_consume_existing_reservations(): void
    {
        $this->stock->update(['quantity_reserved' => 8]);
        $this->expectException(ValidationException::class);
        app(ProductInventoryService::class)->move($this->product, ['counted_quantity' => 7, 'reason' => 'Contagem'], $this->user, $this->company, count: true);
    }

    public function test_company_mismatch_is_rejected(): void
    {
        $other = Company::create(['name' => 'Outra', 'address' => ['city' => 'São Paulo', 'state' => 'SP'], 'document_number' => '22345678000190', 'is_active' => true, 'created_by' => $this->user->id]);
        $this->user->companies()->attach($other, ['role' => 'owner', 'is_active' => true]);
        $this->expectException(HttpException::class);
        app(ProductInventoryService::class)->saveConfiguration($this->product, ['allow_negative' => true, 'quantity_minimum' => 0], $this->user, $other);
    }

    public function test_company_administrator_role_can_adjust_costs(): void
    {
        $this->assignRole(['products.update' => 'company', 'product_stocks.update' => 'company', 'settings.manage' => 'company']);
        app(ProductInventoryService::class)->adjustCosts($this->product, ['average_cost' => 8, 'last_cost' => 9, 'reason' => 'Correção'], $this->user, $this->company);
        $this->assertEquals(8, $this->stock->fresh()->average_cost);
    }

    public function test_product_update_scope_is_rechecked_when_adjusting_costs(): void
    {
        $other = User::factory()->create();
        $this->product->update(['created_by' => $other->id]);
        $this->assignRole(['products.update' => 'own', 'product_stocks.update' => 'company', 'settings.manage' => 'company']);
        $this->expectException(HttpException::class);
        app(ProductInventoryService::class)->adjustCosts($this->product, ['average_cost' => 8, 'last_cost' => 9, 'reason' => 'Correção'], $this->user, $this->company);
    }

    private function assignRole(array $permissions): void
    {
        $this->user->companies()->updateExistingPivot($this->company->id, ['access_mode' => 'roles']);
        $role = CompanyRole::create(['company_id' => $this->company->id, 'name' => 'Administrador',
            'permissions' => $permissions, 'panels' => ['admin'], 'is_active' => true]);
        CompanyRoleAssignment::create(['company_id' => $this->company->id, 'user_id' => $this->user->id,
            'company_role_id' => $role->id, 'is_active' => true, 'granted_by' => $this->user->id]);
    }
}
