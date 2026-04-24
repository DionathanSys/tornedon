<?php

namespace Tests\Feature\Services\Amounts;

use App\Enum\Product\OriginSalePrice;
use App\Enum\Product\Unit;
use App\Enum\Quote\Status as QuoteStatus;
use App\Enum\Requisition\Status as RequisitionStatus;
use App\Enum\ServiceOrder\Priority as ServiceOrderPriority;
use App\Enum\ServiceOrder\State as ServiceOrderState;
use App\Enum\ServiceOrder\Type as ServiceOrderType;
use App\Models\Company;
use App\Models\Partner;
use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommercialAmountPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_order_persists_gross_discount_and_total_amounts(): void
    {
        [$user, $company, $customer] = $this->makeBaseContext();

        $serviceOrder = ServiceOrder::query()->create([
            'number' => 'OS-AMOUNT-001',
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'order_date' => now()->toDateString(),
            'status' => ServiceOrderState::OPEN->value,
            'priority' => ServiceOrderPriority::NORMAL->value,
            'type' => ServiceOrderType::MAINTENANCE->value,
            'travel_value' => 10,
            'created_by' => $user->id,
        ]);

        $service = Service::query()->create([
            'company_id' => $company->id,
            'created_by' => $user->id,
            'service_code' => 'SRV-AMOUNT-001',
            'name' => 'Servico Persistido',
            'price' => 75,
            'tax_rate' => 5,
            'nbs_code' => '123456789',
            'cnae_code' => '6201500',
            'municipal_tax_code' => '01.01',
            'is_active' => true,
        ]);

        $item = ServiceOrderItem::query()->create([
            'service_order_id' => $serviceOrder->id,
            'service_id' => $service->id,
            'quantity' => 1,
            'unit_price' => 75,
            'discount_amount' => 7.5,
            'created_by' => $user->id,
        ]);

        $serviceOrder->refresh();
        $item->refresh();

        $this->assertSame(75.0, (float) $item->gross_amount);
        $this->assertSame(67.5, (float) $item->total_amount);
        $this->assertStoredMoney($item, 'gross_amount', 75.0);

        $this->assertSame(85.0, (float) $serviceOrder->gross_amount);
        $this->assertSame(7.5, (float) $serviceOrder->discount_amount);
        $this->assertSame(77.5, (float) $serviceOrder->total_amount);
        $this->assertStoredMoney($serviceOrder, 'gross_amount', 85.0);
        $this->assertStoredMoney($serviceOrder, 'discount_amount', 7.5);
        $this->assertStoredMoney($serviceOrder, 'total_amount', 77.5);
    }

    public function test_requisition_persists_gross_discount_and_total_amounts(): void
    {
        [$user, $company, $customer] = $this->makeBaseContext();

        $requisition = Requisition::query()->create([
            'number' => 'REQ-AMOUNT-001',
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'sale_date' => now()->toDateString(),
            'status' => RequisitionStatus::OPEN->value,
            'stock_consumed' => false,
            'created_by' => $user->id,
        ]);

        $product = Product::query()->create([
            'company_id' => $company->id,
            'created_by' => $user->id,
            'product_code' => 'PRD-AMOUNT-001',
            'name' => 'Produto Persistido',
            'unit' => Unit::UN->value,
            'origin_sale_price' => OriginSalePrice::FREE->value,
            'sale_price_value' => 100,
            'is_active' => true,
        ]);

        $item = RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'product_id' => $product->id,
            'unit_of_measure' => Unit::UN->value,
            'quantity' => 1,
            'unit_price' => 100,
            'discount_amount' => 15,
            'created_by' => $user->id,
        ]);

        $requisition->refresh();
        $item->refresh();

        $this->assertSame(100.0, (float) $item->gross_amount);
        $this->assertSame(85.0, (float) $item->total_amount);
        $this->assertStoredMoney($item, 'gross_amount', 100.0);

        $this->assertSame(100.0, (float) $requisition->gross_amount);
        $this->assertSame(15.0, (float) $requisition->discount_amount);
        $this->assertSame(85.0, (float) $requisition->total_amount);
        $this->assertStoredMoney($requisition, 'gross_amount', 100.0);
        $this->assertStoredMoney($requisition, 'discount_amount', 15.0);
        $this->assertStoredMoney($requisition, 'total_amount', 85.0);
    }

    public function test_quote_persists_gross_discount_and_total_amounts(): void
    {
        [$user, $company, $customer] = $this->makeBaseContext();

        $quote = Quote::query()->create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'status' => QuoteStatus::DRAFT->value,
            'created_by' => $user->id,
        ]);

        $service = Service::query()->create([
            'company_id' => $company->id,
            'created_by' => $user->id,
            'service_code' => 'SRV-AMOUNT-002',
            'name' => 'Servico Orcamento',
            'price' => 50,
            'tax_rate' => 5,
            'nbs_code' => '123456789',
            'cnae_code' => '6201500',
            'municipal_tax_code' => '01.01',
            'is_active' => true,
        ]);

        $item = QuoteItem::query()->create([
            'quote_id' => $quote->id,
            'service_id' => $service->id,
            'description' => 'Item de orcamento',
            'unit_of_measure' => Unit::UN->value,
            'quantity' => 2,
            'unit_price' => 50,
            'discount_amount' => 10,
            'sequence' => 1,
            'status' => QuoteStatus::DRAFT->value,
        ]);

        $quote->refresh();
        $item->refresh();

        $this->assertSame(100.0, (float) $item->gross_amount);
        $this->assertSame(90.0, (float) $item->total_amount);
        $this->assertStoredMoney($item, 'gross_amount', 100.0);

        $this->assertSame(100.0, (float) $quote->gross_amount);
        $this->assertSame(10.0, (float) $quote->discount_amount);
        $this->assertSame(90.0, (float) $quote->total_amount);
        $this->assertStoredMoney($quote, 'gross_amount', 100.0);
        $this->assertStoredMoney($quote, 'discount_amount', 10.0);
        $this->assertStoredMoney($quote, 'total_amount', 90.0);
    }

    /**
     * @return array{User, Company, Partner}
     */
    private function makeBaseContext(): array
    {
        $user = User::factory()->create();
        $company = Company::query()->create([
            'name' => 'Empresa Amounts',
            'document_number' => '12345678000199',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);
        $customer = Partner::query()->create([
            'name' => 'Cliente Amounts',
            'document_type' => 'CNPJ',
            'document_number' => '22345678000188',
            'created_by' => $user->id,
        ]);

        return [$user, $company, $customer];
    }

    private function assertStoredMoney(object $model, string $column, float $expected): void
    {
        $stored = round(((float) $model->getRawOriginal($column)) / 100, 2);

        $this->assertSame($expected, $stored, "Falha ao validar armazenamento bruto da coluna {$column}.");
    }
}
