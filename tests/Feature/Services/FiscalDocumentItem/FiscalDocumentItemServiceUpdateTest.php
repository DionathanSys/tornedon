<?php

namespace Tests\Feature\Services\FiscalDocumentItem;

use App\Enum\FiscalDocument\BuyerPresenceIndicator;
use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\IssuePurpose;
use App\Enum\FiscalDocument\OperationNature;
use App\Enum\FiscalDocument\OperationType;
use App\Enum\FiscalDocument\Status as FiscalDocumentStatus;
use App\Enum\Product\OriginSalePrice;
use App\Enum\Product\Unit;
use App\Models\Company;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentItem;
use App\Models\FiscalProfile;
use App\Models\Partner;
use App\Models\Product;
use App\Models\ProductTax;
use App\Models\User;
use App\Services\FiscalDocumentItem\FiscalDocumentItemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiscalDocumentItemServiceUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_persists_manual_tax_data_when_updating_item(): void
    {
        [$user, $item] = $this->createScenario();

        $service = app(FiscalDocumentItemService::class);
        $updated = $service->update($item, [
            'tax_data' => [
                'imposto' => [
                    'icms' => [
                        'situacao_tributaria' => '102',
                        'valor_base_calculo' => 250,
                        'aliquota' => 0,
                        'valor' => 0,
                    ],
                    'pis' => [
                        'situacao_tributaria' => '49',
                        'valor_base_calculo' => 250,
                        'aliquota' => 0,
                        'valor' => 0,
                    ],
                    'cofins' => [
                        'situacao_tributaria' => '49',
                        'valor_base_calculo' => 250,
                        'aliquota' => 0,
                        'valor' => 0,
                    ],
                ],
            ],
        ], $user->id);

        $this->assertNotNull($updated, $service->getMessage());
        $this->assertFalse($service->hasError());
        $this->assertSame('102', data_get($updated->tax_data, 'imposto.icms.situacao_tributaria'));
        $this->assertSame('49', data_get($updated->tax_data, 'imposto.pis.situacao_tributaria'));
        $this->assertSame('49', data_get($updated->tax_data, 'imposto.cofins.situacao_tributaria'));
        $this->assertSame('250.00', data_get($updated->fiscalDocument->fresh()->tax_data, 'totais.valor_produtos'));
    }

    private function createScenario(): array
    {
        $user = User::factory()->create();

        $company = Company::query()->create([
            'name' => 'Empresa Item Fiscal',
            'document_number' => '10203040000155',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);

        $customer = Partner::query()->create([
            'name' => 'Cliente Item Fiscal',
            'document_type' => 'CNPJ',
            'document_number' => '22345678000188',
            'created_by' => $user->id,
        ]);

        FiscalProfile::query()->create([
            'company_id' => $company->id,
            'tax_regime' => 'simples_nacional',
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        $product = Product::query()->create([
            'company_id' => $company->id,
            'created_by' => $user->id,
            'product_code' => 'PRD-ITEM-001',
            'name' => 'Alternador',
            'unit' => Unit::UN->value,
            'origin_sale_price' => OriginSalePrice::FREE->value,
            'sale_price_value' => 250,
            'is_active' => true,
        ]);

        ProductTax::query()->create([
            'product_id' => $product->id,
            'product_origin' => '0',
            'ncm_code' => '85114000',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $document = FiscalDocument::query()->create([
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'status' => FiscalDocumentStatus::PENDING->value,
            'document_type' => DocumentModel::NFE->value,
            'issued_at' => now()->toDateString(),
            'movement_at' => now()->toDateString(),
            'document_number' => '100',
            'document_series' => '1',
            'operation_nature' => OperationNature::VENDA_DENTRO_ESTADO->value,
            'operation_type' => OperationType::SAIDA->value,
            'issue_purpose' => IssuePurpose::NORMAL->value,
            'is_final_consumer' => false,
            'buyer_presence_indicator' => BuyerPresenceIndicator::OUTROS->value,
            'freight_data' => ['modalidade_frete' => '9'],
            'created_by' => $user->id,
        ]);

        $item = FiscalDocumentItem::query()->create([
            'fiscal_document_id' => $document->id,
            'product_id' => $product->id,
            'product_code' => $product->product_code,
            'description' => $product->name,
            'item_number' => 1,
            'product_origin' => '0',
            'ncm_code' => '85114000',
            'cfop_code' => '5102',
            'quantity' => 1,
            'unit_of_measure' => Unit::UN->value,
            'unit_price' => 250,
            'total_price' => 250,
            'included_in_total' => true,
            'created_by' => $user->id,
        ]);

        return [$user, $item->fresh('fiscalDocument')];
    }
}
