<?php

namespace Tests\Feature\Filament\ServiceOrders;

use App\Enum\Equipment\Type as EquipmentType;
use App\Enum\FiscalDocument\BuyerPresenceIndicator;
use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\FreightModality;
use App\Enum\FiscalDocument\IssuePurpose;
use App\Enum\FiscalDocument\OperationNature;
use App\Enum\FiscalDocument\OperationType;
use App\Enum\FiscalDocument\Status;
use App\Enum\Product\OriginSalePrice;
use App\Enum\Product\Unit;
use App\Enum\ServiceOrder\Priority;
use App\Enum\ServiceOrder\State;
use App\Enum\ServiceOrder\Type;
use App\Filament\Clusters\Sales\Resources\FiscalDocuments\FiscalDocumentResource as SalesFiscalDocumentResource;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\EditServiceOrder;
use App\Models\Company;
use App\Models\Equipment;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentItem;
use App\Models\FiscalProfile;
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

class GenerateRepairReturnFiscalDocumentActionTest extends TestCase
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

    public function test_edit_service_order_action_generates_return_document_and_redirects(): void
    {
        [$user, $company, $serviceOrder] = $this->createScenario();

        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
        Filament::setTenant($company);

        $component = Livewire::test(EditServiceOrder::class, ['record' => (string) $serviceOrder->getRouteKey()])
            ->assertActionExists('generateRepairReturnFiscalDocument')
            ->assertActionVisible('generateRepairReturnFiscalDocument')
            ->callAction('generateRepairReturnFiscalDocument')
            ->assertHasNoActionErrors();

        $returnDocument = FiscalDocument::query()
            ->where('operation_nature', OperationNature::RETORNO_CONSERTO->value)
            ->where('operation_type', OperationType::SAIDA->value)
            ->first();

        $this->assertNotNull($returnDocument);
        $component->assertRedirect(SalesFiscalDocumentResource::getUrl('edit', ['record' => $returnDocument]));
    }

    public function test_edit_service_order_page_renders_remittance_tab_with_operational_data(): void
    {
        [$user, $company, $serviceOrder] = $this->createScenario();

        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
        Filament::setTenant($company);

        Livewire::test(EditServiceOrder::class, ['record' => (string) $serviceOrder->getRouteKey()])
            ->assertSee('Remessa')
            ->assertSee('Itens vinculados')
            ->assertSee('Ativos recebidos pela nota de remessa')
            ->assertSee('Notebook Panasonic Toughbook')
            ->assertSee('7001');
    }

    private function createScenario(): array
    {
        $user = User::factory()->create();

        $company = Company::query()->create([
            'name' => 'Empresa Retorno Filament',
            'document_number' => '12345678000199',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'email' => 'empresa-retorno-filament@example.com',
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        $customer = Partner::query()->create([
            'name' => 'Cliente Retorno Filament',
            'document_type' => 'CNPJ',
            'document_number' => '22345678000188',
            'created_by' => $user->id,
        ]);

        FiscalProfile::query()->create([
            'company_id' => $company->id,
            'tax_regime' => 'simples_nacional',
            'cfop_rules' => [
                OperationNature::RETORNO_CONSERTO->value => [
                    'default_cfop' => '5916',
                ],
            ],
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        $user->companies()->attach($company, [
            'role' => 'admin',
            'is_active' => true,
        ]);

        $originDocument = FiscalDocument::query()->create([
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'status' => Status::CONFIRMED->value,
            'document_type' => DocumentModel::NFE->value,
            'issued_at' => now()->subDay()->toDateString(),
            'movement_at' => now()->subDay()->toDateString(),
            'document_number' => '7001',
            'document_series' => '1',
            'document_key' => '35260412345678000199550010000070011000000701',
            'operation_nature' => OperationNature::REMESSA_CONSERTO->value,
            'operation_type' => OperationType::ENTRADA->value,
            'issue_purpose' => IssuePurpose::NORMAL->value,
            'is_final_consumer' => false,
            'buyer_presence_indicator' => BuyerPresenceIndicator::OUTROS->value,
            'freight_data' => ['modalidade_frete' => FreightModality::SEM_FRETE->value],
            'pending' => false,
            'confirmed' => true,
            'canceled' => false,
            'created_by' => $user->id,
        ]);

        $product = Product::query()->create([
            'company_id' => $company->id,
            'created_by' => $user->id,
            'product_code' => 'PRD-FIL-RET-001',
            'name' => 'Notebook industrial',
            'unit' => Unit::UN->value,
            'origin_sale_price' => OriginSalePrice::FREE->value,
            'sale_price_value' => 100,
            'is_active' => true,
        ]);

        $originItem = FiscalDocumentItem::query()->create([
            'fiscal_document_id' => $originDocument->id,
            'product_id' => $product->id,
            'product_code' => $product->product_code,
            'description' => $product->name,
            'item_number' => 1,
            'product_origin' => '0',
            'ncm_code' => '84713012',
            'cfop_code' => '1915',
            'quantity' => 1,
            'unit_of_measure' => Unit::UN->value,
            'unit_price' => 100,
            'total_price' => 100,
            'included_in_total' => true,
            'tax_data' => ['imposto' => ['icms' => ['situacao_tributaria' => '00']]],
            'created_by' => $user->id,
        ]);

        $equipment = Equipment::query()->create([
            'company_id' => $company->id,
            'owner_id' => $customer->id,
            'name' => 'Notebook Panasonic Toughbook',
            'type' => EquipmentType::OTHER->value,
            'serial_number' => 'TB-01',
            'created_by' => $user->id,
        ]);

        $serviceOrder = ServiceOrder::query()->create([
            'number' => '00001',
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'order_date' => now()->toDateString(),
            'status' => State::OPEN->value,
            'priority' => Priority::NORMAL->value,
            'type' => Type::MAINTENANCE->value,
            'equipment_id' => $equipment->id,
            'created_by' => $user->id,
        ]);

        $asset = RemittanceAsset::query()->create([
            'company_id' => $company->id,
            'fiscal_document_id' => $originDocument->id,
            'fiscal_document_item_id' => $originItem->id,
            'product_id' => $product->id,
            'equipment_id' => $equipment->id,
            'serial_number' => 'TB-01',
            'received_quantity' => 1,
            'status' => 'received',
            'created_by' => $user->id,
        ]);

        $serviceOrder->remittanceAssets()->attach($asset->id, [
            'quantity_allocated' => 1,
        ]);

        return [$user, $company, $serviceOrder];
    }
}
