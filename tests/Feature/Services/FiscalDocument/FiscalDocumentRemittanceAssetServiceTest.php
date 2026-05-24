<?php

namespace Tests\Feature\Services\FiscalDocument;

use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\IssuePurpose;
use App\Enum\FiscalDocument\OperationNature;
use App\Enum\FiscalDocument\OperationType;
use App\Enum\FiscalDocument\Status;
use App\Enum\Product\OriginSalePrice;
use App\Enum\Product\Unit;
use App\Models\Company;
use App\Models\Equipment;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentItem;
use App\Models\Partner;
use App\Models\Product;
use App\Models\User;
use App\Services\FiscalDocument\FiscalDocumentRemittanceAssetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiscalDocumentRemittanceAssetServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_equipment_and_remittance_asset_for_entry_item(): void
    {
        [$user, $company, $customer, $item] = $this->createEntryItemScenario();

        $service = app(FiscalDocumentRemittanceAssetService::class);

        $asset = $service->saveForItem($item, [
            'mode' => 'new',
            'equipment_name' => 'Notebook Dell',
            'equipment_type' => \App\Enum\Equipment\Type::OTHER->value,
            'equipment_serial_number' => 'SN-NEW-001',
            'asset_serial_number' => 'SN-RECEB-001',
            'lot_number' => 'LT-01',
            'received_quantity' => 1,
        ], $user->id);

        $this->assertNotNull($asset);
        $this->assertFalse($service->hasError());

        $this->assertDatabaseHas('equipments', [
            'company_id' => $company->id,
            'owner_id' => $customer->id,
            'name' => 'Notebook Dell',
            'serial_number' => 'SN-NEW-001',
        ]);

        $this->assertDatabaseHas('remittance_assets', [
            'id' => $asset->id,
            'company_id' => $company->id,
            'fiscal_document_id' => $item->fiscal_document_id,
            'fiscal_document_item_id' => $item->id,
            'equipment_id' => $asset->equipment_id,
            'serial_number' => 'SN-RECEB-001',
            'lot_number' => 'LT-01',
            'status' => 'received',
        ]);
    }

    public function test_it_links_existing_equipment_owned_by_document_customer(): void
    {
        [$user, $company, $customer, $item] = $this->createEntryItemScenario();

        $equipment = Equipment::query()->create([
            'company_id' => $company->id,
            'owner_id' => $customer->id,
            'name' => 'Impressora Zebra',
            'type' => \App\Enum\Equipment\Type::OTHER->value,
            'serial_number' => 'EQ-002',
            'created_by' => $user->id,
        ]);

        $service = app(FiscalDocumentRemittanceAssetService::class);

        $asset = $service->saveForItem($item, [
            'mode' => 'existing',
            'equipment_id' => $equipment->id,
            'asset_serial_number' => 'EQ-002',
            'received_quantity' => 1,
        ], $user->id);

        $this->assertNotNull($asset);
        $this->assertFalse($service->hasError());
        $this->assertSame($equipment->id, $asset->equipment_id);
    }

    private function createEntryItemScenario(): array
    {
        $user = User::factory()->create();

        $company = Company::query()->create([
            'name' => 'Empresa Remessa',
            'document_number' => '12345678000199',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'email' => 'empresa-remessa@example.com',
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        $customer = Partner::query()->create([
            'name' => 'Cliente da Remessa',
            'document_type' => 'CNPJ',
            'document_number' => '22345678000188',
            'created_by' => $user->id,
        ]);

        $document = FiscalDocument::query()->create([
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'status' => Status::PENDING->value,
            'document_type' => DocumentModel::NFE->value,
            'issued_at' => now()->toDateString(),
            'movement_at' => now()->toDateString(),
            'document_number' => '1001',
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
            'product_code' => 'PRD-001',
            'name' => 'Equipamento recebido',
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

        return [$user, $company, $customer, $item];
    }
}
