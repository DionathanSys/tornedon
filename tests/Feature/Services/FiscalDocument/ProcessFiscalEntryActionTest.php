<?php

namespace Tests\Feature\Services\FiscalDocument;

use App\Enum\Product\OriginSalePrice;
use App\Enum\Product\Unit;
use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\Status as FiscalDocumentStatus;
use App\Enum\Payment\Condition;
use App\Enum\Payment\Method;
use App\Models\Company;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentItem;
use App\Models\Partner;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\FiscalDocument\Actions\ProcessFiscalEntryAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcessFiscalEntryActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_process_fiscal_entry_generates_account_payable_without_breaking_current_flow(): void
    {
        $user = User::factory()->create();

        $company = Company::create([
            'name' => 'Empresa Entrada Fiscal',
            'document_number' => '12345678000177',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);

        $supplier = Partner::create([
            'name' => 'Fornecedor Fiscal',
            'document_type' => 'CNPJ',
            'document_number' => '22345678000155',
            'created_by' => $user->id,
        ]);

        $document = FiscalDocument::create([
            'customer_id' => $supplier->id,
            'company_id' => $company->id,
            'status' => FiscalDocumentStatus::CONFIRMED->value,
            'issued_at' => '2026-04-08',
            'movement_at' => '2026-04-08',
            'document_type' => DocumentModel::NFE->value,
            'document_number' => 'NF-ENT-100',
            'document_series' => '1',
            'created_by' => $user->id,
        ]);

        FiscalDocumentItem::create([
            'fiscal_document_id' => $document->id,
            'product_id' => null,
            'product_code' => 'SEM-ESTOQUE',
            'description' => 'Servico de terceiros',
            'item_number' => 1,
            'product_origin' => null,
            'ncm_code' => null,
            'cfop_code' => null,
            'quantity' => 1,
            'unit_of_measure' => 'UN',
            'unit_price' => 100,
            'total_price' => 100,
            'included_in_total' => true,
            'created_by' => $user->id,
        ]);

        $result = app(ProcessFiscalEntryAction::class)->execute($document, [
            'payment_method' => Method::PIX->value,
            'payment_condition' => Condition::CASH->value,
            'due_date' => '2026-04-08',
            'description' => 'NF de entrada teste',
        ], $user->id);

        $resultJson = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertSame(1, $result['payables'], $resultJson ?: 'Sem retorno serializavel.');
        $this->assertSame(0, $result['stock_movements']);
        $this->assertCount(0, $result['errors']);
        $this->assertDatabaseCount('account_payables', 1);
        $this->assertDatabaseCount('account_payable_installments', 1);
    }

    public function test_process_fiscal_entry_uses_taxable_unit_and_quantity_for_stock_entry_when_available(): void
    {
        $user = User::factory()->create();

        $company = Company::create([
            'name' => 'Empresa Entrada Fiscal Estoque',
            'document_number' => '12345678000178',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);

        $supplier = Partner::create([
            'name' => 'Fornecedor Fiscal Estoque',
            'document_type' => 'CNPJ',
            'document_number' => '22345678000156',
            'created_by' => $user->id,
        ]);

        $product = Product::query()->create([
            'company_id' => $company->id,
            'created_by' => $user->id,
            'product_code' => 'PRD-ENT-001',
            'name' => 'Produto Entrada',
            'unit' => Unit::JG->value,
            'has_stock_control' => true,
            'origin_sale_price' => OriginSalePrice::FREE->value,
            'sale_price_value' => 100,
            'is_active' => true,
        ]);

        ProductStock::query()->create([
            'product_id' => $product->id,
            'company_id' => $company->id,
            'quantity_total' => 0,
            'quantity_reserved' => 0,
            'is_active' => true,
            'allow_negative' => false,
            'created_by' => $user->id,
        ]);

        $document = FiscalDocument::create([
            'customer_id' => $supplier->id,
            'company_id' => $company->id,
            'status' => FiscalDocumentStatus::CONFIRMED->value,
            'issued_at' => '2026-04-08',
            'movement_at' => '2026-04-08',
            'document_type' => DocumentModel::NFE->value,
            'document_number' => 'NF-ENT-101',
            'document_series' => '1',
            'created_by' => $user->id,
        ]);

        FiscalDocumentItem::create([
            'fiscal_document_id' => $document->id,
            'product_id' => $product->id,
            'product_code' => $product->product_code,
            'description' => $product->name,
            'item_number' => 1,
            'product_origin' => '0',
            'ncm_code' => '84733049',
            'quantity' => 16,
            'unit_of_measure' => Unit::PC->value,
            'taxable_unit' => Unit::JG->value,
            'taxable_quantity' => 2,
            'unit_price' => 5,
            'total_price' => 80,
            'taxable_unit_price' => 40,
            'included_in_total' => true,
            'created_by' => $user->id,
        ]);

        $result = app(ProcessFiscalEntryAction::class)->execute($document, [
            'payment_method' => Method::PIX->value,
            'payment_condition' => Condition::CASH->value,
            'due_date' => '2026-04-08',
            'description' => 'NF de entrada com unidade tributavel',
        ], $user->id);

        $movement = StockMovement::query()->latest('id')->first();

        $this->assertSame(1, $result['stock_movements']);
        $this->assertNotNull($movement);
        $this->assertSame(Unit::JG->value, $movement->operational_unit);
        $this->assertEquals(2.0, (float) $movement->operational_quantity);
        $this->assertEquals(2.0, (float) $movement->base_quantity);
        $this->assertEquals(2.0, (float) $movement->quantity);
        $this->assertEquals(2.0, (float) $product->stock()->first()->quantity_total);
    }
}
