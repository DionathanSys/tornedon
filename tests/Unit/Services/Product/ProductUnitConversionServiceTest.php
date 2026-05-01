<?php

namespace Tests\Unit\Services\Product;

use App\Enum\Product\Unit;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductAlternativeUnit;
use App\Models\User;
use App\Services\Product\ProductUnitConversionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ProductUnitConversionServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProductUnitConversionService $service;
    private User $user;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ProductUnitConversionService();
        $this->user = User::query()->create([
            'name' => 'Teste Produto',
            'email' => 'produto@example.com',
            'password' => bcrypt('password'),
        ]);
        $this->company = Company::query()->create([
            'name' => 'Empresa Produto',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'email' => 'empresa@example.com',
            'created_by' => $this->user->id,
        ]);
    }

    public function test_it_converts_alternative_unit_to_base_unit(): void
    {
        $product = $this->makeProduct([
            'unit' => Unit::JG,
        ]);

        ProductAlternativeUnit::query()->create([
            'product_id' => $product->id,
            'unit' => Unit::CX,
            'conversion_factor' => 2,
        ]);

        $result = $this->service->convertToBase($product->fresh(), Unit::CX->value, 3);

        $this->assertSame('CX', $result->operationalUnit);
        $this->assertSame('JG', $result->baseUnit);
        $this->assertEquals(6.0, $result->baseQuantity);
        $this->assertEquals(2.0, $result->factor);
    }

    public function test_it_converts_from_base_unit_to_smaller_operational_unit(): void
    {
        $product = $this->makeProduct([
            'unit' => Unit::JG,
        ]);

        ProductAlternativeUnit::query()->create([
            'product_id' => $product->id,
            'unit' => Unit::PC,
            'conversion_factor' => 0.125,
        ]);

        $result = $this->service->convertFromBase($product->fresh(), Unit::PC->value, 2);

        $this->assertSame('PC', $result->operationalUnit);
        $this->assertSame('JG', $result->baseUnit);
        $this->assertEquals(16.0, $result->operationalQuantity);
        $this->assertEquals(2.0, $result->baseQuantity);
        $this->assertEquals(0.125, $result->factor);
    }

    public function test_it_rejects_unit_not_configured_for_product(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $product = $this->makeProduct([
            'unit' => Unit::JG,
        ]);

        $this->service->convertToBase($product, Unit::CX->value, 1);
    }

    public function test_it_lists_available_units_with_base_first(): void
    {
        $product = $this->makeProduct([
            'unit' => Unit::JG,
        ]);

        ProductAlternativeUnit::query()->create([
            'product_id' => $product->id,
            'unit' => Unit::CX,
            'conversion_factor' => 2,
        ]);

        ProductAlternativeUnit::query()->create([
            'product_id' => $product->id,
            'unit' => Unit::PC,
            'conversion_factor' => 0.125,
        ]);

        $this->assertSame(['JG', 'CX', 'PC'], $this->service->getAvailableUnits($product->fresh()));
    }

    private function makeProduct(array $overrides = []): Product
    {
        return Product::query()->create(array_merge([
            'company_id' => $this->company->id,
            'created_by' => $this->user->id,
            'product_code' => 'PRD-' . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            'name' => 'Produto teste',
            'unit' => Unit::UN->value,
            'origin_sale_price' => 'free',
            'sale_price_value' => 10,
            'is_active' => true,
        ], $overrides));
    }
}
