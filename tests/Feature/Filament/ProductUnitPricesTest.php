<?php

namespace Tests\Feature\Filament;

use App\Enum\Product\OriginSalePrice;
use App\Enum\Product\Unit;
use App\Filament\Clusters\Sales\Resources\Quotes\Schemas\Components\SchemaForm;
use App\Filament\Clusters\Sales\Resources\Requisitions\Schemas\ItemsForm;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Component;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductUnitPricesTest extends TestCase
{
    use RefreshDatabase;

    public static function contexts(): array
    {
        return [['requisition'], ['quote']];
    }

    #[DataProvider('contexts')]
    public function test_switching_units_converts_negotiated_price_minimum_discount_and_total(string $context): void
    {
        $user = User::factory()->create();
        $company = Company::factory()->create(['created_by' => $user->id]);
        $user->companies()->attach($company, ['role' => 'admin', 'is_active' => true]);
        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
        Filament::setTenant($company);
        $product = Product::factory()->create([
            'company_id' => $company->id, 'created_by' => $user->id,
            'unit' => Unit::CT, 'origin_sale_price' => OriginSalePrice::CALCULATED,
            'profit_margin' => 100, 'min_sale_price' => 500,
        ]);
        $product->alternativeUnitConversions()->create(['unit' => Unit::UN, 'conversion_factor' => 0.01]);
        $stock = ProductStock::create([
            'product_id' => $product->id, 'company_id' => $company->id,
            'quantity_total' => 0.1, 'average_cost' => 265, 'created_by' => $user->id,
        ]);

        $page = Livewire::test(ProductUnitPriceForm::class, ['context' => $context, 'data' => [
            'product_stock_id' => $stock->id, 'product_id' => $product->id,
            'item' => ['real_product_id' => $product->id, 'min_sale_price' => 500],
            'unit_of_measure' => 'CT', 'quantity' => 1, 'unit_price' => 600,
            'discount_percentage' => 10, 'discount_amount' => 60,
        ]])
            ->set('data.unit_of_measure', 'UN')
            ->assertSet('data.unit_price', '6,00')->assertSet('data.item.min_sale_price', 5)
            ->assertSet('data.discount_amount', '0,60')->assertSet('data.total_amount', '5,40')
            ->set('data.unit_of_measure', 'CT')
            ->assertSet('data.unit_price', '600,00')->assertSet('data.item.min_sale_price', 500)
            ->assertSet('data.discount_amount', '60,00')->assertSet('data.total_amount', '540,00');

        $product->update(['sale_unit' => Unit::UN]);
        $selectionField = $context === 'requisition' ? 'product_stock_lookup_id' : 'item.product_stock_id';
        $page->set('data.'.$selectionField, $stock->id)
            ->assertSet('data.unit_of_measure', 'UN')->assertSet('data.unit_price', '5,30')
            ->assertSet('data.item.min_sale_price', 5);
    }
}

class ProductUnitPriceForm extends Component implements HasSchemas
{
    use InteractsWithSchemas;

    public array $data = [];

    public string $context;

    public function mount(string $context, array $data): void
    {
        $this->context = $context;
        $this->form->fill($data);
    }

    public function form(Schema $schema): Schema
    {
        $schema->statePath('data');

        return $this->context === 'requisition'
            ? ItemsForm::configure($schema)
            : $schema->components(SchemaForm::make());
    }

    public function render(): string
    {
        return '<div></div>';
    }
}
