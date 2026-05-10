<?php

namespace Tests\Feature\Services\FiscalDocument;

use App\Enum\AccountPayable\Status as AccountPayableStatus;
use App\Enum\FiscalDocument\BuyerPresenceIndicator;
use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\IssuePurpose;
use App\Enum\FiscalDocument\OperationNature;
use App\Enum\FiscalDocument\OperationType;
use App\Enum\FiscalDocument\PurchaseReturnSettlementMode;
use App\Enum\FiscalDocument\Status as FiscalDocumentStatus;
use App\Enum\Product\OriginSalePrice;
use App\Enum\Product\Unit;
use App\Enum\PurchaseReturnCredit\Status as PurchaseReturnCreditStatus;
use App\Enum\StockMovement\Type as StockMovementType;
use App\Models\AccountPayable;
use App\Models\Company;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentItem;
use App\Models\FiscalDocumentItemOrigin;
use App\Models\Partner;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\PurchaseReturnCredit;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\FiscalDocument\Actions\ProcessAuthorizedPurchaseReturnAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcessAuthorizedPurchaseReturnActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_generates_supplier_credit_and_return_stock_without_duplication(): void
    {
        [$user, $originDocument, $returnDocument, $returnItem, $originPayable] = $this->createScenario(
            PurchaseReturnSettlementMode::SUPPLIER_CREDIT,
            100,
        );

        $service = app(ProcessAuthorizedPurchaseReturnAction::class);

        $first = $service->execute($returnDocument, $user->id);
        $second = $service->execute($returnDocument->fresh(), $user->id);

        $originPayable->refresh();
        $credit = PurchaseReturnCredit::query()->first();

        $this->assertSame(1, $first['stock_movements']);
        $this->assertSame([], $first['errors']);
        $this->assertSame([], $first['warnings']);
        $this->assertSame(0, $second['stock_movements']);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseCount('purchase_return_credits', 1);
        $this->assertSame(AccountPayableStatus::PENDING, $originPayable->status);
        $this->assertNotNull($credit);
        $this->assertSame($returnDocument->id, $credit->return_fiscal_document_id);
        $this->assertSame(PurchaseReturnCreditStatus::OPEN, $credit->status);
        $this->assertEquals(100.0, (float) $credit->amount);
        $this->assertEquals(100.0, (float) $credit->available_amount);
        $this->assertDatabaseHas('stock_movements', [
            'source_type' => 'fiscal_document_item',
            'source_id' => $returnItem->id,
            'type' => StockMovementType::RETURN->value,
        ]);
        $this->assertNotNull($returnDocument->fresh()->return_financial_processed_at);
        $this->assertNotNull($returnDocument->fresh()->return_stock_processed_at);
    }

    public function test_it_cancels_open_payables_when_configured_to_cancel(): void
    {
        [$user, $originDocument, $returnDocument, $returnItem, $originPayable] = $this->createScenario(
            PurchaseReturnSettlementMode::CANCEL_PAYABLE,
            100,
        );

        $result = app(ProcessAuthorizedPurchaseReturnAction::class)->execute($returnDocument, $user->id);

        $originPayable->refresh();

        $this->assertSame([], $result['errors']);
        $this->assertSame(AccountPayableStatus::CANCELLED, $originPayable->status);
        $this->assertStringContainsString('Cancelada por devolução', (string) $originPayable->description);
        $this->assertDatabaseCount('purchase_return_credits', 0);
        $this->assertDatabaseHas('stock_movements', [
            'source_type' => 'fiscal_document_item',
            'source_id' => $returnItem->id,
            'type' => StockMovementType::RETURN->value,
        ]);
    }

    public function test_it_replaces_open_payable_with_new_boleto_using_remaining_balance(): void
    {
        [$user, $originDocument, $returnDocument, $returnItem, $originPayable] = $this->createScenario(
            PurchaseReturnSettlementMode::REPLACE_PAYABLE,
            40,
            ['replacement_due_date' => '2026-05-20']
        );

        $result = app(ProcessAuthorizedPurchaseReturnAction::class)->execute($returnDocument, $user->id);

        $originPayable->refresh();

        $replacementPayable = AccountPayable::query()
            ->where('fiscal_document_id', $returnDocument->id)
            ->first();

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['replacement_payables']);
        $this->assertSame(AccountPayableStatus::CANCELLED, $originPayable->status);
        $this->assertNotNull($replacementPayable);
        $this->assertSame(AccountPayableStatus::PENDING, $replacementPayable->status);
        $this->assertEquals(60.0, (float) $replacementPayable->due_amount);
        $this->assertSame('2026-05-20', $replacementPayable->due_date?->format('Y-m-d'));
        $this->assertDatabaseCount('purchase_return_credits', 0);
        $this->assertDatabaseHas('stock_movements', [
            'source_type' => 'fiscal_document_item',
            'source_id' => $returnItem->id,
            'type' => StockMovementType::RETURN->value,
        ]);
    }

    private function createScenario(PurchaseReturnSettlementMode $mode, float $returnAmount, array $overrides = []): array
    {
        $user = User::factory()->create();

        $company = Company::query()->create([
            'name' => 'Empresa Devolucao Processada',
            'document_number' => '12345678000199',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);

        $supplier = Partner::query()->create([
            'name' => 'Fornecedor Devolucao',
            'document_type' => 'CNPJ',
            'document_number' => '22345678000188',
            'created_by' => $user->id,
        ]);

        $product = Product::query()->create([
            'company_id' => $company->id,
            'created_by' => $user->id,
            'product_code' => 'PRD-RET-001',
            'name' => 'Produto devolvido',
            'unit' => Unit::UN->value,
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

        $originDocument = FiscalDocument::query()->create([
            'customer_id' => $supplier->id,
            'company_id' => $company->id,
            'status' => FiscalDocumentStatus::CONFIRMED->value,
            'document_type' => DocumentModel::NFE->value,
            'issued_at' => now()->subDay()->toDateString(),
            'movement_at' => now()->subDay()->toDateString(),
            'document_number' => '12345',
            'document_series' => '1',
            'document_key' => '35260412345678000199550010000123451000012345',
            'operation_nature' => OperationNature::VENDA_DENTRO_ESTADO->value,
            'operation_type' => OperationType::ENTRADA->value,
            'issue_purpose' => IssuePurpose::NORMAL->value,
            'is_final_consumer' => false,
            'buyer_presence_indicator' => BuyerPresenceIndicator::OUTROS->value,
            'pending' => false,
            'confirmed' => true,
            'canceled' => false,
            'created_by' => $user->id,
            'confirmed_by' => $user->id,
            'confirmed_at' => now()->subDay(),
        ]);

        $originItem = FiscalDocumentItem::query()->create([
            'fiscal_document_id' => $originDocument->id,
            'product_id' => $product->id,
            'product_code' => $product->product_code,
            'description' => $product->name,
            'item_number' => 1,
            'product_origin' => '0',
            'ncm_code' => '84733049',
            'cfop_code' => '1202',
            'quantity' => 2,
            'unit_of_measure' => 'UN',
            'taxable_unit' => 'UN',
            'taxable_quantity' => 2,
            'unit_price' => 50,
            'total_price' => 100,
            'included_in_total' => true,
            'created_by' => $user->id,
        ]);

        $originPayable = AccountPayable::query()->create([
            'supplier_id' => $supplier->id,
            'company_id' => $company->id,
            'fiscal_document_id' => $originDocument->id,
            'sequence_number' => '01',
            'status' => AccountPayableStatus::PENDING->value,
            'due_date' => '2026-05-10',
            'paid_date' => null,
            'due_amount' => 100,
            'paid_amount' => 0,
            'description' => 'Titulo original da compra',
            'document_number' => $originDocument->document_number,
            'paid' => false,
            'payment_method' => 'boleto',
        ]);

        $returnDocument = FiscalDocument::query()->create([
            'customer_id' => $supplier->id,
            'company_id' => $company->id,
            'status' => FiscalDocumentStatus::CONFIRMED->value,
            'document_type' => DocumentModel::NFE->value,
            'issued_at' => now()->toDateString(),
            'movement_at' => now()->toDateString(),
            'document_number' => '54321',
            'document_series' => '1',
            'operation_nature' => OperationNature::DEVOLUCAO_COMPRA->value,
            'operation_type' => OperationType::SAIDA->value,
            'issue_purpose' => IssuePurpose::DEVOLUCAO->value,
            'is_final_consumer' => false,
            'buyer_presence_indicator' => BuyerPresenceIndicator::OUTROS->value,
            'tax_data' => [
                'purchase_return_origin' => [
                    'fiscal_document_id' => $originDocument->id,
                    'document_number' => $originDocument->document_number,
                ],
            ],
            'return_financial_data' => [
                'mode' => $mode->value,
                'notes' => 'Processamento de teste',
                ...$overrides,
            ],
            'pending' => false,
            'confirmed' => true,
            'canceled' => false,
            'created_by' => $user->id,
            'nfe_status' => 'authorized',
        ]);

        $returnItem = FiscalDocumentItem::query()->create([
            'fiscal_document_id' => $returnDocument->id,
            'product_id' => $product->id,
            'product_code' => $product->product_code,
            'description' => $product->name,
            'item_number' => 1,
            'product_origin' => '0',
            'ncm_code' => '84733049',
            'cfop_code' => '5202',
            'quantity' => 1,
            'unit_of_measure' => 'UN',
            'taxable_unit' => 'UN',
            'taxable_quantity' => 1,
            'unit_price' => $returnAmount,
            'total_price' => $returnAmount,
            'included_in_total' => true,
            'created_by' => $user->id,
        ]);

        FiscalDocumentItemOrigin::query()->create([
            'origin_fiscal_document_id' => $originDocument->id,
            'origin_fiscal_document_item_id' => $originItem->id,
            'return_fiscal_document_id' => $returnDocument->id,
            'return_fiscal_document_item_id' => $returnItem->id,
            'linked_quantity' => 1,
            'linked_value' => $returnAmount,
            'origin_document_key' => $originDocument->document_key,
        ]);

        return [$user, $originDocument, $returnDocument, $returnItem, $originPayable];
    }
}
