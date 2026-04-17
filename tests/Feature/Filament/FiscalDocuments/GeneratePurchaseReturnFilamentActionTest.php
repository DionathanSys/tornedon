<?php

namespace Tests\Feature\Filament\FiscalDocuments;

use App\Enum\FiscalDocument\BuyerPresenceIndicator;
use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\FreightModality;
use App\Enum\FiscalDocument\IssuePurpose;
use App\Enum\FiscalDocument\OperationNature;
use App\Enum\FiscalDocument\OperationType;
use App\Enum\FiscalDocument\Status;
use App\Filament\Clusters\Financial\Resources\FiscalDocuments\Pages\EditFiscalDocument;
use App\Filament\Clusters\Sales\Resources\FiscalDocuments\FiscalDocumentResource as SalesFiscalDocumentResource;
use App\Models\Company;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentItem;
use App\Models\FiscalProfile;
use App\Models\Partner;
use App\Models\Product;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class GeneratePurchaseReturnFilamentActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $compiledPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tornedon-views-test-' . Str::uuid();
        File::ensureDirectoryExists($compiledPath);

        config(['view.compiled' => $compiledPath]);
    }

    public function test_edit_entry_document_action_generates_return_and_redirects_to_sales_document(): void
    {
        [$user, $company, $originDocument] = $this->createScenario();

        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
        Filament::setTenant($company);

        $component = Livewire::test(EditFiscalDocument::class, ['record' => (string) $originDocument->getRouteKey()])
            ->assertActionExists('generatePurchaseReturn')
            ->assertActionVisible('generatePurchaseReturn')
            ->callAction('generatePurchaseReturn')
            ->assertHasNoActionErrors();

        $returnDocument = FiscalDocument::query()
            ->where('operation_type', OperationType::SAIDA->value)
            ->where('issue_purpose', IssuePurpose::DEVOLUCAO->value)
            ->first();

        $this->assertNotNull($returnDocument);

        $component->assertRedirect(SalesFiscalDocumentResource::getUrl('edit', ['record' => $returnDocument]));
    }

    private function createScenario(): array
    {
        $user = User::factory()->create();

        $company = Company::query()->create([
            'name' => 'Empresa Filament Devolução',
            'document_number' => '12345678000199',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'email' => 'empresa@example.com',
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        $supplier = Partner::query()->create([
            'name' => 'Fornecedor Filament',
            'document_type' => 'CNPJ',
            'document_number' => '22345678000188',
            'created_by' => $user->id,
        ]);

        $user->companies()->attach($company, [
            'role' => 'admin',
            'is_active' => true,
        ]);

        FiscalProfile::query()->create([
            'company_id' => $company->id,
            'tax_regime' => 'simples_nacional',
            'cfop_rules' => [
                OperationNature::DEVOLUCAO_COMPRA->value => [
                    'default_cfop' => '5202',
                ],
            ],
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        $originDocument = FiscalDocument::query()->create([
            'customer_id' => $supplier->id,
            'company_id' => $company->id,
            'status' => Status::CONFIRMED->value,
            'document_type' => DocumentModel::NFE->value,
            'issued_at' => now()->subDay()->toDateString(),
            'movement_at' => now()->subDay()->toDateString(),
            'document_number' => '321',
            'document_series' => '1',
            'document_key' => '35260412345678000199550010000003211000000321',
            'operation_nature' => OperationNature::DEVOLUCAO_COMPRA->value,
            'operation_type' => OperationType::ENTRADA->value,
            'issue_purpose' => IssuePurpose::NORMAL->value,
            'is_final_consumer' => false,
            'buyer_presence_indicator' => BuyerPresenceIndicator::OUTROS->value,
            'freight_data' => [
                'modalidade_frete' => FreightModality::SEM_FRETE->value,
            ],
            'pending' => false,
            'confirmed' => true,
            'canceled' => false,
            'created_by' => $user->id,
        ]);

        $product = Product::query()->create([
            'company_id' => $company->id,
            'created_by' => $user->id,
            'product_code' => 'PRD-FIL-001',
            'name' => 'Produto Filament',
            'unit' => \App\Enum\Product\Unit::UN->value,
            'origin_sale_price' => \App\Enum\Product\OriginSalePrice::FREE->value,
            'sale_price_value' => 100,
            'is_active' => true,
        ]);

        FiscalDocumentItem::query()->create([
            'fiscal_document_id' => $originDocument->id,
            'product_id' => $product->id,
            'product_code' => $product->product_code,
            'description' => $product->name,
            'item_number' => 1,
            'product_origin' => '0',
            'ncm_code' => '84733049',
            'cest_code' => '1234567',
            'cfop_code' => '1202',
            'quantity' => 1,
            'unit_of_measure' => 'UN',
            'unit_price' => 100,
            'total_price' => 100,
            'included_in_total' => true,
            'tax_data' => [
                'imposto' => [
                    'icms' => ['situacao_tributaria' => '00'],
                    'pis' => ['situacao_tributaria' => '01'],
                    'cofins' => ['situacao_tributaria' => '01'],
                ],
            ],
            'created_by' => $user->id,
        ]);

        return [$user, $company, $originDocument];
    }
}
