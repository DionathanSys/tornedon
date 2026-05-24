<?php

namespace Tests\Feature\Filament\FiscalDocuments;

use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\OperationType;
use App\Enum\FiscalDocument\Status;
use App\Enum\Product\OriginSalePrice;
use App\Enum\Product\Unit;
use App\Filament\Clusters\Financial\Resources\FiscalDocuments\Pages\EditFiscalDocument;
use App\Models\Company;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentItem;
use App\Models\Partner;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class ConfirmEntryFilamentActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $compiledPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tornedon-views-test-'.Str::uuid();
        File::ensureDirectoryExists($compiledPath);

        config(['view.compiled' => $compiledPath]);

        $bladeCompiler = app('blade.compiler');
        $reflection = new \ReflectionClass($bladeCompiler);
        $cachePath = $reflection->getProperty('cachePath');
        $cachePath->setAccessible(true);
        $cachePath->setValue($bladeCompiler, $compiledPath);
    }

    public function test_confirm_entry_rolls_back_everything_when_any_item_fails(): void
    {
        $user = User::factory()->create();

        $company = Company::query()->create([
            'name' => 'Empresa Confirmacao Entrada',
            'document_number' => '12345678000191',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'email' => 'empresa-confirmacao@example.com',
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        $supplier = Partner::query()->create([
            'name' => 'Fornecedor Confirmacao',
            'document_type' => 'CNPJ',
            'document_number' => '22345678000181',
            'created_by' => $user->id,
        ]);

        $validProduct = Product::query()->create([
            'company_id' => $company->id,
            'product_code' => 'PRD-OK',
            'name' => 'Produto com estoque',
            'unit' => Unit::UN->value,
            'has_stock_control' => true,
            'origin_sale_price' => OriginSalePrice::FREE->value,
            'sale_price_value' => 10,
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        $invalidProduct = Product::query()->create([
            'company_id' => $company->id,
            'product_code' => 'PRD-ERRO',
            'name' => 'Produto sem estoque',
            'unit' => Unit::UN->value,
            'has_stock_control' => true,
            'origin_sale_price' => OriginSalePrice::FREE->value,
            'sale_price_value' => 20,
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        ProductStock::query()->create([
            'product_id' => $validProduct->id,
            'company_id' => $company->id,
            'quantity_total' => 0,
            'quantity_reserved' => 0,
            'is_active' => true,
            'allow_negative' => false,
            'created_by' => $user->id,
        ]);

        $document = FiscalDocument::query()->create([
            'customer_id' => $supplier->id,
            'company_id' => $company->id,
            'status' => Status::PENDING->value,
            'issued_at' => now()->toDateString(),
            'movement_at' => now()->toDateString(),
            'document_type' => DocumentModel::NFE->value,
            'operation_type' => OperationType::ENTRADA->value,
            'document_number' => 'NF-ENT-ROLLBACK',
            'document_series' => '1',
            'pending' => true,
            'confirmed' => false,
            'canceled' => false,
            'created_by' => $user->id,
        ]);

        FiscalDocumentItem::query()->create([
            'fiscal_document_id' => $document->id,
            'product_id' => $validProduct->id,
            'product_code' => $validProduct->product_code,
            'description' => $validProduct->name,
            'item_number' => 1,
            'quantity' => 3,
            'unit_of_measure' => Unit::UN->value,
            'unit_price' => 10,
            'total_price' => 30,
            'created_by' => $user->id,
        ]);

        FiscalDocumentItem::query()->create([
            'fiscal_document_id' => $document->id,
            'product_id' => $invalidProduct->id,
            'product_code' => $invalidProduct->product_code,
            'description' => $invalidProduct->name,
            'item_number' => 2,
            'quantity' => 1,
            'unit_of_measure' => Unit::UN->value,
            'unit_price' => 20,
            'total_price' => 20,
            'created_by' => $user->id,
        ]);

        $user->companies()->attach($company, [
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
        Filament::setTenant($company);

        Livewire::test(EditFiscalDocument::class, ['record' => (string) $document->getRouteKey()])
            ->assertActionExists('confirmEntry')
            ->assertActionVisible('confirmEntry')
            ->callAction('confirmEntry');

        $document->refresh();

        $this->assertFalse($document->confirmed);
        $this->assertTrue($document->pending);
        $this->assertSame(Status::PENDING, $document->status);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame(0.0, (float) ProductStock::query()->where('product_id', $validProduct->id)->value('quantity_total'));
        $this->assertNull(StockMovement::query()->first());
    }
}
