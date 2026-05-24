<?php

namespace Tests\Feature\Filament\FiscalDocuments;

use App\Enum\Equipment\Type as EquipmentType;
use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\IssuePurpose;
use App\Enum\FiscalDocument\OperationNature;
use App\Enum\FiscalDocument\OperationType;
use App\Enum\FiscalDocument\Status;
use App\Enum\Product\OriginSalePrice;
use App\Enum\Product\Unit;
use App\Enum\ServiceOrder\Priority;
use App\Enum\ServiceOrder\Type;
use App\Filament\Clusters\Financial\Resources\FiscalDocuments\Pages\EditFiscalDocument;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\ServiceOrderResource;
use App\Models\Company;
use App\Models\Equipment;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentItem;
use App\Models\Partner;
use App\Models\Product;
use App\Models\RemittanceAsset;
use App\Models\ServiceOrder;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class CreateServiceOrderFromEntryFilamentActionTest extends TestCase
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

    public function test_edit_entry_document_action_creates_service_order_and_redirects_to_edit_page(): void
    {
        [$user, $company, $document, $asset] = $this->createScenario();

        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
        Filament::setTenant($company);

        $component = Livewire::test(EditFiscalDocument::class, ['record' => (string) $document->getRouteKey()])
            ->assertActionExists('createServiceOrderFromEntry')
            ->assertActionVisible('createServiceOrderFromEntry')
            ->callAction('createServiceOrderFromEntry', data: [
                'remittance_asset_ids' => [(string) $asset->id],
                'primary_remittance_asset_id' => $asset->id,
                'order_date' => now()->toDateString(),
                'priority' => Priority::NORMAL->value,
                'type' => Type::MAINTENANCE->value,
                'customer_observations' => 'Criada a partir da nota de entrada.',
                'open_service_order' => true,
            ])
            ->assertHasNoActionErrors();

        $serviceOrder = ServiceOrder::query()->first();

        $this->assertNotNull($serviceOrder);
        $this->assertSame($document->customer_id, $serviceOrder->customer_id);
        $this->assertSame($asset->equipment_id, $serviceOrder->equipment_id);

        $this->assertDatabaseHas('service_order_received_assets', [
            'service_order_id' => $serviceOrder->id,
            'remittance_asset_id' => $asset->id,
        ]);

        $component->assertRedirect(ServiceOrderResource::getUrl('edit', ['record' => $serviceOrder]));
    }

    private function createScenario(): array
    {
        $user = User::factory()->create();

        $company = Company::query()->create([
            'name' => 'Empresa Nota Entrada',
            'document_number' => '12345678000199',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'email' => 'empresa-nf@example.com',
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        $customer = Partner::query()->create([
            'name' => 'Cliente Oficina',
            'document_type' => 'CNPJ',
            'document_number' => '22345678000188',
            'created_by' => $user->id,
        ]);

        $user->companies()->attach($company, [
            'role' => 'admin',
            'is_active' => true,
        ]);

        $document = FiscalDocument::query()->create([
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'status' => Status::PENDING->value,
            'document_type' => DocumentModel::NFE->value,
            'issued_at' => now()->subDay()->toDateString(),
            'movement_at' => now()->subDay()->toDateString(),
            'document_number' => '654',
            'document_series' => '1',
            'operation_nature' => OperationNature::REMESSA_CONSERTO->value,
            'operation_type' => OperationType::ENTRADA->value,
            'issue_purpose' => IssuePurpose::NORMAL->value,
            'pending' => true,
            'confirmed' => false,
            'canceled' => false,
            'created_by' => $user->id,
        ]);

        $product = Product::query()->create([
            'company_id' => $company->id,
            'created_by' => $user->id,
            'product_code' => 'PRD-OS-001',
            'name' => 'Impressora Industrial',
            'unit' => Unit::UN->value,
            'origin_sale_price' => OriginSalePrice::FREE->value,
            'sale_price_value' => 100,
            'is_active' => true,
        ]);

        $item = FiscalDocumentItem::query()->create([
            'fiscal_document_id' => $document->id,
            'product_id' => $product->id,
            'product_code' => $product->product_code,
            'description' => $product->name,
            'item_number' => 1,
            'quantity' => 1,
            'unit_of_measure' => Unit::UN->value,
            'unit_price' => 100,
            'total_price' => 100,
            'included_in_total' => true,
            'created_by' => $user->id,
        ]);

        $equipment = Equipment::query()->create([
            'company_id' => $company->id,
            'owner_id' => $customer->id,
            'name' => 'Impressora Zebra ZT230',
            'type' => EquipmentType::OTHER->value,
            'serial_number' => 'ZT230-001',
            'created_by' => $user->id,
        ]);

        $asset = RemittanceAsset::query()->create([
            'company_id' => $company->id,
            'fiscal_document_id' => $document->id,
            'fiscal_document_item_id' => $item->id,
            'product_id' => $product->id,
            'equipment_id' => $equipment->id,
            'serial_number' => 'ZT230-001',
            'received_quantity' => 1,
            'status' => 'received',
            'created_by' => $user->id,
        ]);

        return [$user, $company, $document, $asset];
    }
}
