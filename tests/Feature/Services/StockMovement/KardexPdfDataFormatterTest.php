<?php

namespace Tests\Feature\Services\StockMovement;

use App\Enum\Product\OriginSalePrice;
use App\Enum\Product\Unit;
use App\Enum\StockMovement\Type;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\StockMovement\Support\KardexPdfDataFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KardexPdfDataFormatterTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_calculates_opening_and_running_balances_for_kardex(): void
    {
        $user = User::factory()->create();

        $company = Company::query()->create([
            'name' => 'Empresa Kardex',
            'document_number' => '12345678000190',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);

        $product = Product::query()->create([
            'company_id' => $company->id,
            'created_by' => $user->id,
            'has_stock_control' => true,
            'name' => 'Produto Kardex',
            'product_code' => 'KDX-001',
            'unit' => Unit::UN->value,
            'origin_sale_price' => OriginSalePrice::FREE->value,
            'sale_price_value' => 10,
            'is_active' => true,
        ]);

        $stock = ProductStock::query()->create([
            'product_id' => $product->id,
            'company_id' => $company->id,
            'quantity_total' => 0,
            'quantity_reserved' => 0,
            'is_active' => true,
            'allow_negative' => false,
            'created_by' => $user->id,
        ]);

        StockMovement::query()->create([
            'product_stock_id' => $stock->id,
            'product_id' => $product->id,
            'company_id' => $company->id,
            'type' => Type::ENTRY->value,
            'operational_unit' => Unit::UN->value,
            'operational_quantity' => 10,
            'base_unit' => Unit::UN->value,
            'base_quantity' => 10,
            'quantity' => 10,
            'reason' => 'Saldo anterior',
            'source_type' => 'manual',
            'source_id' => 1,
            'created_by' => $user->id,
            'created_at' => '2026-05-01 08:00:00',
            'updated_at' => '2026-05-01 08:00:00',
        ]);

        StockMovement::query()->create([
            'product_stock_id' => $stock->id,
            'product_id' => $product->id,
            'company_id' => $company->id,
            'type' => Type::RESERVATION->value,
            'operational_unit' => Unit::UN->value,
            'operational_quantity' => 2,
            'base_unit' => Unit::UN->value,
            'base_quantity' => 2,
            'quantity' => 2,
            'reason' => 'Reserva anterior',
            'source_type' => 'manual',
            'source_id' => 2,
            'created_by' => $user->id,
            'created_at' => '2026-05-02 08:00:00',
            'updated_at' => '2026-05-02 08:00:00',
        ]);

        StockMovement::query()->create([
            'product_stock_id' => $stock->id,
            'product_id' => $product->id,
            'company_id' => $company->id,
            'type' => Type::EXIT->value,
            'operational_unit' => Unit::UN->value,
            'operational_quantity' => 3,
            'base_unit' => Unit::UN->value,
            'base_quantity' => 3,
            'quantity' => 3,
            'reason' => 'Venda',
            'source_type' => 'manual',
            'source_id' => 3,
            'created_by' => $user->id,
            'created_at' => '2026-05-03 09:00:00',
            'updated_at' => '2026-05-03 09:00:00',
        ]);

        StockMovement::query()->create([
            'product_stock_id' => $stock->id,
            'product_id' => $product->id,
            'company_id' => $company->id,
            'type' => Type::RESERVATION_RELEASE->value,
            'operational_unit' => Unit::UN->value,
            'operational_quantity' => 1,
            'base_unit' => Unit::UN->value,
            'base_quantity' => 1,
            'quantity' => 1,
            'reason' => 'Liberacao',
            'source_type' => 'manual',
            'source_id' => 4,
            'created_by' => $user->id,
            'created_at' => '2026-05-04 09:00:00',
            'updated_at' => '2026-05-04 09:00:00',
        ]);

        $formatter = app(KardexPdfDataFormatter::class);

        $data = $formatter->format($product->fresh('company'), $company->id, [
            'start_date' => '2026-05-03',
            'end_date' => '2026-05-31',
        ]);

        $this->assertSame('10,000', $data['opening']['stock_balance']);
        $this->assertSame('2,000', $data['opening']['reserved_balance']);
        $this->assertSame('8,000', $data['opening']['available_balance']);
        $this->assertCount(2, $data['rows']);
        $this->assertSame('7,000', $data['rows'][0]['stock_balance']);
        $this->assertSame('2,000', $data['rows'][0]['reserved_balance']);
        $this->assertSame('5,000', $data['rows'][0]['available_balance']);
        $this->assertSame('7,000', $data['summary']['closing_stock_balance']);
        $this->assertSame('1,000', $data['summary']['closing_reserved_balance']);
        $this->assertSame('6,000', $data['summary']['closing_available_balance']);
    }
}
