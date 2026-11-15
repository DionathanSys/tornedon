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
use App\Services\StockMovement\Actions\CalculateStockMovementStateAction;
use App\Services\StockMovement\Actions\PrintKardexPdfAction;
use App\Services\StockMovement\Support\KardexPdfDataFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KardexPdfDataFormatterTest extends TestCase
{
    use RefreshDatabase;

    private function createScenario(): array
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

        return [$user, $company, $product, $stock];
    }

    public function test_it_calculates_opening_and_running_balances_for_kardex(): void
    {
        [$user, $company, $product, $stock] = $this->createScenario();

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

    public function test_reservation_sale_price_is_separate_from_historical_cost_and_stock_value(): void
    {
        [$user, $company, $product, $stock] = $this->createScenario();
        $this->record($stock, $user, 'entry', 7, 8.15, '2026-11-05 13:06:00');
        $this->record($stock, $user, 'reservation', 1, 15, '2026-11-13 09:03:00');
        $stock->update(['average_cost' => 999, 'quantity_total' => 999]);

        $data = app(KardexPdfDataFormatter::class)->format($product, $company->id);

        $this->assertSame('R$ 15,00', $data['rows'][1]['operational_unit_price']);
        $this->assertSame('R$ 8,1500', $data['rows'][1]['average_cost']);
        $this->assertSame('R$ 57,05', $data['rows'][0]['stock_value']);
        $this->assertSame('R$ 57,05', $data['rows'][1]['stock_value']);
        $this->assertSame('R$ 57,05', $data['summary']['closing_stock_value']);
        $this->assertSame('R$ 8,15', $data['summary']['closing_reserved_value']);
        $this->assertSame('R$ 48,90', $data['summary']['closing_available_value']);

        $html = view('pdf.stock-kardex', ['pdfData' => $data])->render();
        $this->assertStringContainsString('Preço Oper.', $html);
        $this->assertStringContainsString('Custo Médio / Base', $html);
        $this->assertStringNotContainsString('Custo Total</th>', $html);

        $pdf = app(PrintKardexPdfAction::class)->execute($product, $company->id);
        $this->assertNotNull($pdf);
        $this->assertStringStartsWith('%PDF-', base64_decode($pdf, true));

        $emptyPeriod = app(KardexPdfDataFormatter::class)->format($product, $company->id, [
            'start_date' => '2026-11-14', 'end_date' => '2026-11-15',
        ]);
        $this->assertSame([], $emptyPeriod['rows']);
        $this->assertSame('R$ 57,05', $emptyPeriod['opening']['stock_value']);
        $this->assertSame('R$ 57,05', $emptyPeriod['summary']['closing_stock_value']);
    }

    public function test_period_opening_and_each_purchase_use_the_chronological_average_cost(): void
    {
        [$user, $company, $product, $stock] = $this->createScenario();
        $this->record($stock, $user, 'entry', 10, 10, '2026-10-01 08:00:00');
        $this->record($stock, $user, 'exit', 10, 30, '2026-11-01 08:00:00');
        $this->record($stock, $user, 'entry', 10, 20, '2026-11-02 08:00:00');
        $this->record($stock, $user, 'entry', 10, 100, '2026-12-01 08:00:00');

        $data = app(KardexPdfDataFormatter::class)->format($product, $company->id, [
            'start_date' => '2026-11-01', 'end_date' => '2026-11-30',
        ]);

        $this->assertSame('R$ 10,0000', $data['opening']['average_cost']);
        $this->assertSame('R$ 100,00', $data['opening']['stock_value']);
        $this->assertCount(2, $data['rows']);
        $this->assertSame('R$ 30,00', $data['rows'][0]['operational_unit_price']);
        $this->assertSame('R$ 10,0000', $data['rows'][0]['average_cost']);
        $this->assertSame('R$ 0,00', $data['rows'][0]['stock_value']);
        $this->assertSame('R$ 20,0000', $data['rows'][1]['average_cost']);
        $this->assertSame('R$ 200,00', $data['summary']['closing_stock_value']);
    }

    public function test_conversion_and_administrative_cost_adjustment_are_replayed_without_changing_quantity(): void
    {
        [$user, $company, $product, $stock] = $this->createScenario();
        $this->record($stock, $user, 'entry', 24, 120, '2026-11-01 08:00:00', [
            'operational_unit' => 'CX', 'operational_quantity' => 2, 'conversion_factor_snapshot' => 12,
        ]);
        $this->record($stock, $user, 'adjustment', 0, 0, '2026-11-02 08:00:00', [
            'additional_info' => ['operation' => 'administrative_cost_adjustment',
                'average_cost' => 12.3456, 'last_cost' => 13],
        ]);

        $data = app(KardexPdfDataFormatter::class)->format($product, $company->id);

        $this->assertSame('R$ 120,00', $data['rows'][0]['operational_unit_price']);
        $this->assertSame('R$ 10,0000', $data['rows'][0]['average_cost']);
        $this->assertSame('R$ 240,00', $data['rows'][0]['stock_value']);
        $this->assertSame('-', $data['rows'][1]['operational_unit_price']);
        $this->assertSame('24,000', $data['rows'][1]['stock_balance']);
        $this->assertSame('R$ 12,3456', $data['rows'][1]['average_cost']);
        $this->assertSame('R$ 296,29', $data['rows'][1]['stock_value']);
    }

    public function test_recorded_opening_snapshot_is_included_in_balances_and_valuation(): void
    {
        [$user, $company, $product, $stock] = $this->createScenario();
        $calculator = app(CalculateStockMovementStateAction::class);
        $opening = $calculator->emptyState();
        $stock->update(['additional_info' => ['movement_opening_snapshot' => [
            ...$opening, 'quantity_total' => 10, 'quantity_reserved' => 2, 'average_cost' => 5, 'last_cost' => 5,
        ]]]);
        $this->record($stock, $user, 'entry', 5, 20, '2026-11-01 08:00:00');

        $data = app(KardexPdfDataFormatter::class)->format($product, $company->id);

        $this->assertSame('10,000', $data['opening']['stock_balance']);
        $this->assertSame('R$ 50,00', $data['opening']['stock_value']);
        $this->assertSame('15,000', $data['summary']['closing_stock_balance']);
        $this->assertSame('R$ 10,0000', $data['summary']['closing_average_cost']);
        $this->assertSame('R$ 150,00', $data['summary']['closing_stock_value']);
        $this->assertSame('R$ 20,00', $data['summary']['closing_reserved_value']);
        $this->assertSame('R$ 130,00', $data['summary']['closing_available_value']);
    }

    private function record(ProductStock $stock, User $user, string $type, float $quantity, float $price, string $date, array $overrides = []): void
    {
        $movement = new StockMovement;
        $movement->forceFill([
            'company_id' => $stock->company_id, 'product_id' => $stock->product_id,
            'product_stock_id' => $stock->id, 'type' => $type, 'quantity' => $quantity,
            'operational_unit' => 'UN', 'operational_quantity' => $quantity,
            'base_unit' => 'UN', 'base_quantity' => $quantity, 'conversion_factor_snapshot' => 1,
            'unit_price' => $price, 'source_type' => 'manual', 'source_id' => 0,
            'created_by' => $user->id, 'created_at' => $date, 'updated_at' => $date,
            ...$overrides,
        ])->save();
    }
}
