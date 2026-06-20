<?php

namespace Tests\Feature\Services\FiscalDocument;

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
use App\Services\FiscalDocument\RepairReturnFiscalDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepairReturnFiscalDocumentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_generates_repair_return_document_from_service_order(): void
    {
        [$user, $originDocument, $serviceOrder, $asset] = $this->createScenario();

        $service = app(RepairReturnFiscalDocumentService::class);
        $returnDocument = $service->generateFromServiceOrder($serviceOrder, $user->id);

        $this->assertNotNull($returnDocument);
        $this->assertFalse($service->hasError());
        $this->assertSame(OperationNature::RETORNO_CONSERTO, $returnDocument->operation_nature);
        $this->assertSame(OperationType::SAIDA, $returnDocument->operation_type);
        $this->assertSame($originDocument->id, data_get($returnDocument->tax_data, 'reference.fiscal_document_id'));
        $this->assertSame($originDocument->document_key, data_get($returnDocument->tax_data, 'reference.document_key'));
        $this->assertSame($serviceOrder->id, data_get($returnDocument->tax_data, 'reference.service_order_id'));

        $returnItem = FiscalDocumentItem::query()
            ->where('fiscal_document_id', $returnDocument->id)
            ->first();

        $this->assertNotNull($returnItem);
        $this->assertSame('5916', $returnItem->cfop_code);

        $this->assertDatabaseHas('fiscal_document_item_origins', [
            'origin_fiscal_document_id' => $originDocument->id,
            'origin_fiscal_document_item_id' => $asset->fiscal_document_item_id,
            'return_fiscal_document_id' => $returnDocument->id,
        ]);

        $asset->refresh();
        $this->assertSame((float) $asset->received_quantity, (float) $asset->returned_quantity);
        $this->assertSame('returned', $asset->status);
        $this->assertSame($returnDocument->id, $serviceOrder->fresh()->linkedReturnFiscalDocument()?->id);
    }

    private function createScenario(): array
    {
        $user = User::factory()->create();

        $company = Company::query()->create([
            'name' => 'Empresa Retorno OS',
            'document_number' => '12345678000199',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'email' => 'empresa-retorno-os@example.com',
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        $customer = Partner::query()->create([
            'name' => 'Cliente Retorno OS',
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

        $originDocument = FiscalDocument::query()->create([
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'status' => Status::CONFIRMED->value,
            'document_type' => DocumentModel::NFE->value,
            'issued_at' => now()->subDay()->toDateString(),
            'movement_at' => now()->subDay()->toDateString(),
            'document_number' => '9901',
            'document_series' => '1',
            'document_key' => '35260412345678000199550010000099011000000991',
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
            'product_code' => 'PRD-RET-001',
            'name' => 'Coletor de dados',
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
            'ncm_code' => '84733049',
            'cfop_code' => '1915',
            'quantity' => 1,
            'unit_of_measure' => Unit::UN->value,
            'unit_price' => 100,
            'total_price' => 100,
            'included_in_total' => true,
            'tax_data' => [
                'imposto' => [
                    'icms' => ['situacao_tributaria' => '00'],
                ],
            ],
            'created_by' => $user->id,
        ]);

        $equipment = Equipment::query()->create([
            'company_id' => $company->id,
            'owner_id' => $customer->id,
            'name' => 'Coletor Zebra MC3300',
            'type' => EquipmentType::OTHER->value,
            'serial_number' => 'MC3300-01',
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
            'serial_number' => 'MC3300-01',
            'received_quantity' => 1,
            'status' => 'received',
            'created_by' => $user->id,
        ]);

        $serviceOrder->remittanceAssets()->attach($asset->id, [
            'quantity_allocated' => 1,
        ]);

        return [$user, $originDocument, $serviceOrder, $asset];
    }
}
