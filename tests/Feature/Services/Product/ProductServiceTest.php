<?php

namespace Tests\Feature\Services\Product;

use App\Enum\Product\Unit;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Services\Product\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProductService $service;
    private User $user;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ProductService();
        $this->user = User::query()->create([
            'name' => 'Usuario Produto',
            'email' => 'produto-service@example.com',
            'password' => bcrypt('password'),
        ]);
        $this->company = Company::query()->create([
            'name' => 'Empresa Produto',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'email' => 'empresa-produto@example.com',
            'created_by' => $this->user->id,
        ]);
    }

    public function test_it_creates_product_and_persists_alternative_unit_conversions(): void
    {
        $product = $this->service->create([
            'company_id' => $this->company->id,
            'name' => 'Produto teste conversao',
            'unit' => Unit::JG->value,
            'alternative_unit_conversions' => [
                ['unit' => Unit::CX->value, 'conversion_factor' => 2],
                ['unit' => Unit::PC->value, 'conversion_factor' => 0.125],
            ],
        ], $this->user->id);

        $this->assertNotNull($product);
        $this->assertTrue($this->service->isSuccess());
        $this->assertSame([Unit::CX->value, Unit::PC->value], $product->fresh()->alternative_units);
        $this->assertDatabaseHas('product_alternative_units', [
            'product_id' => $product->id,
            'unit' => Unit::CX->value,
            'conversion_factor' => '2.00000000',
        ]);
        $this->assertDatabaseHas('product_alternative_units', [
            'product_id' => $product->id,
            'unit' => Unit::PC->value,
            'conversion_factor' => '0.12500000',
        ]);
    }

    public function test_it_updates_product_and_replaces_alternative_unit_conversions(): void
    {
        $product = Product::query()->create([
            'company_id' => $this->company->id,
            'created_by' => $this->user->id,
            'product_code' => 'PRD-1001',
            'name' => 'Produto update',
            'unit' => Unit::JG->value,
            'origin_sale_price' => 'free',
            'sale_price_value' => 10,
            'is_active' => true,
            'alternative_units' => [Unit::CX->value],
        ]);

        $product->alternativeUnitConversions()->create([
            'unit' => Unit::CX->value,
            'conversion_factor' => 2,
        ]);

        $updated = $this->service->update($product, [
            'alternative_unit_conversions' => [
                ['unit' => Unit::PC->value, 'conversion_factor' => 0.125],
            ],
        ], $this->user->id);

        $this->assertNotNull($updated);
        $this->assertTrue($this->service->isSuccess());
        $this->assertSame([Unit::PC->value], $updated->fresh()->alternative_units);
        $this->assertDatabaseMissing('product_alternative_units', [
            'product_id' => $product->id,
            'unit' => Unit::CX->value,
        ]);
        $this->assertDatabaseHas('product_alternative_units', [
            'product_id' => $product->id,
            'unit' => Unit::PC->value,
            'conversion_factor' => '0.12500000',
        ]);
    }

    public function test_it_keeps_legacy_alternative_units_input_in_sync_with_conversion_table(): void
    {
        $product = Product::query()->create([
            'company_id' => $this->company->id,
            'created_by' => $this->user->id,
            'product_code' => 'PRD-1002',
            'name' => 'Produto legado',
            'unit' => Unit::JG->value,
            'origin_sale_price' => 'free',
            'sale_price_value' => 10,
            'is_active' => true,
        ]);

        $updated = $this->service->update($product, [
            'alternative_units' => [Unit::CX->value, Unit::PC->value],
        ], $this->user->id);

        $this->assertNotNull($updated);
        $this->assertSame([Unit::CX->value, Unit::PC->value], $updated->fresh()->alternative_units);
        $this->assertDatabaseHas('product_alternative_units', [
            'product_id' => $product->id,
            'unit' => Unit::CX->value,
            'conversion_factor' => '1.00000000',
        ]);
        $this->assertDatabaseHas('product_alternative_units', [
            'product_id' => $product->id,
            'unit' => Unit::PC->value,
            'conversion_factor' => '1.00000000',
        ]);
    }
}
