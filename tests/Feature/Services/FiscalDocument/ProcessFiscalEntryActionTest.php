<?php

namespace Tests\Feature\Services\FiscalDocument;

use App\Enum\Product\OriginSalePrice;
use App\Enum\Product\Unit;
use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\OperationType;
use App\Enum\Financial\FinancialAccountType;
use App\Enum\FiscalDocument\Status as FiscalDocumentStatus;
use App\Enum\Payment\Condition;
use App\Enum\Payment\Method;
use App\Models\AccountPayable;
use App\Models\AccountPayableInstallment;
use App\Models\CompanyCreditCard;
use App\Models\Company;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentItem;
use App\Models\FinancialAccount;
use App\Models\Partner;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\FiscalDocument\Actions\GenerateFiscalEntryCardTransactionAction;
use App\Services\FiscalDocument\Actions\GenerateFiscalEntryPayableAction;
use App\Services\FiscalDocument\Actions\ProcessFiscalEntryStockAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcessFiscalEntryActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_fiscal_entry_payable_creates_account_payable_for_confirmed_entry(): void
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
            'operation_type' => OperationType::ENTRADA->value,
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

        $result = app(GenerateFiscalEntryPayableAction::class)->execute($document, [
            'payment_method' => Method::PIX->value,
            'payment_condition' => Condition::CASH->value,
            'due_date' => '2026-04-08',
            'description' => 'NF de entrada teste',
        ], $user->id);

        $resultJson = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertSame(1, $result['payables'], $resultJson ?: 'Sem retorno serializavel.');
        $this->assertCount(0, $result['errors']);
        $this->assertDatabaseCount('account_payables', 1);
        $this->assertDatabaseCount('account_payable_installments', 1);
    }

    public function test_generate_fiscal_entry_payable_creates_single_account_with_multiple_installments(): void
    {
        $user = User::factory()->create();

        $company = Company::create([
            'name' => 'Empresa Entrada Parcelada',
            'document_number' => '12345678000179',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);

        $supplier = Partner::create([
            'name' => 'Fornecedor Parcelado',
            'document_type' => 'CNPJ',
            'document_number' => '22345678000157',
            'created_by' => $user->id,
        ]);

        $document = FiscalDocument::create([
            'customer_id' => $supplier->id,
            'company_id' => $company->id,
            'status' => FiscalDocumentStatus::CONFIRMED->value,
            'issued_at' => '2026-04-08',
            'movement_at' => '2026-04-08',
            'document_type' => DocumentModel::NFE->value,
            'operation_type' => OperationType::ENTRADA->value,
            'document_number' => 'NF-ENT-102',
            'document_series' => '1',
            'created_by' => $user->id,
        ]);

        FiscalDocumentItem::create([
            'fiscal_document_id' => $document->id,
            'product_id' => null,
            'product_code' => 'SRV-001',
            'description' => 'Servico parcelado',
            'item_number' => 1,
            'product_origin' => null,
            'ncm_code' => null,
            'cfop_code' => null,
            'quantity' => 1,
            'unit_of_measure' => 'UN',
            'unit_price' => 200,
            'total_price' => 200,
            'included_in_total' => true,
            'created_by' => $user->id,
        ]);

        $result = app(GenerateFiscalEntryPayableAction::class)->execute($document, [
            'payment_method' => Method::PIX->value,
            'payment_condition' => Condition::DAYS_30_60->value,
            'due_date' => '2026-05-08',
            'description' => 'NF parcelada teste',
        ], $user->id);

        $resultJson = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertSame(1, $result['payables'], $resultJson ?: 'Sem retorno serializavel.');
        $this->assertCount(0, $result['errors']);
        $this->assertDatabaseCount('account_payables', 1);
        $this->assertDatabaseCount('account_payable_installments', 2);

        $accountPayable = AccountPayable::query()->with('installments')->sole();

        $this->assertEquals(200.0, (float) $accountPayable->due_amount);

        $installments = $accountPayable->installments->sortBy('sequence_number')->values();

        $this->assertCount(2, $installments);
        $this->assertSame(['01', '02'], $installments->pluck('sequence_number')->all());
        $this->assertSame(['2026-05-08', '2026-06-07'], $installments->map(fn (AccountPayableInstallment $installment): string => $installment->due_date->toDateString())->all());
        $this->assertSame([100.0, 100.0], $installments->map(fn (AccountPayableInstallment $installment): float => (float) $installment->due_amount)->all());
    }

    public function test_process_fiscal_entry_stock_uses_taxable_unit_and_quantity_when_available(): void
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
            'operation_type' => OperationType::ENTRADA->value,
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

        $result = app(ProcessFiscalEntryStockAction::class)->execute($document, $user->id);

        $movement = StockMovement::query()->latest('id')->first();

        $this->assertSame(1, $result['stock_movements']);
        $this->assertSame([], $result['errors']);
        $this->assertNotNull($movement);
        $this->assertSame(Unit::JG->value, $movement->operational_unit);
        $this->assertEquals(2.0, (float) $movement->operational_quantity);
        $this->assertEquals(2.0, (float) $movement->base_quantity);
        $this->assertEquals(2.0, (float) $movement->quantity);
        $this->assertEquals(2.0, (float) $product->stock()->first()->quantity_total);
    }

    public function test_generate_fiscal_entry_card_transaction_creates_installment_lines_without_payable(): void
    {
        $user = User::factory()->create();

        $company = Company::create([
            'name' => 'Empresa Entrada Cartao',
            'document_number' => '12345678000999',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);

        $supplier = Partner::create([
            'name' => 'Fornecedor Cartao',
            'document_type' => 'CNPJ',
            'document_number' => '22345678000159',
            'created_by' => $user->id,
        ]);

        $issuer = Partner::create([
            'name' => 'Banco Emissor Cartao',
            'document_type' => 'CNPJ',
            'document_number' => '11345678000111',
            'created_by' => $user->id,
        ]);

        $financialAccount = FinancialAccount::create([
            'company_id' => $company->id,
            'name' => 'Conta Principal',
            'type' => FinancialAccountType::BANK->value,
            'opening_balance' => 0,
            'is_active' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $card = CompanyCreditCard::create([
            'company_id' => $company->id,
            'name' => 'Cartao Corporativo Fiscal',
            'issuer' => 'Banco Emissor Cartao',
            'issuer_partner_id' => $issuer->id,
            'last_four' => '9988',
            'closing_day' => 25,
            'due_day' => 5,
            'statement_cutoff_business_days' => 2,
            'default_financial_account_id' => $financialAccount->id,
            'active' => true,
        ]);

        $document = FiscalDocument::create([
            'customer_id' => $supplier->id,
            'company_id' => $company->id,
            'status' => FiscalDocumentStatus::CONFIRMED->value,
            'issued_at' => '2026-05-10',
            'movement_at' => '2026-05-10',
            'document_type' => DocumentModel::NFE->value,
            'operation_type' => OperationType::ENTRADA->value,
            'document_number' => 'NF-ENT-CARD-01',
            'document_series' => '1',
            'created_by' => $user->id,
        ]);

        FiscalDocumentItem::create([
            'fiscal_document_id' => $document->id,
            'product_id' => null,
            'product_code' => 'COMPRA-CARD',
            'description' => 'Compra em cartao',
            'item_number' => 1,
            'product_origin' => null,
            'ncm_code' => null,
            'cfop_code' => null,
            'quantity' => 1,
            'unit_of_measure' => 'UN',
            'unit_price' => 900,
            'total_price' => 900,
            'included_in_total' => true,
            'created_by' => $user->id,
        ]);

        $result = app(GenerateFiscalEntryCardTransactionAction::class)->execute($document, [
            'company_credit_card_id' => $card->id,
            'payment_method' => Method::CREDIT_CARD->value,
            'payment_condition' => Condition::INSTALLMENTS_3X->value,
            'card_transaction_date' => '2026-05-10',
            'description' => 'Compra NF em cartao',
        ], $user->id);

        $resultJson = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertSame(3, $result['transactions'], $resultJson ?: 'Sem retorno serializavel.');
        $this->assertCount(0, $result['errors']);
        $this->assertDatabaseCount('company_card_transactions', 3);
        $this->assertDatabaseCount('account_payables', 0);
    }
}
