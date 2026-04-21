<?php

namespace Tests\Feature\Services\Financial;

use App\Enum\AccountPayable\Status as AccountPayableStatus;
use App\Enum\AccountReceivable\Status as AccountReceivableStatus;
use App\Enum\Financial\CashMovementDirection;
use App\Enum\Financial\FinancialAccountType;
use App\Enum\Invoice\Status as InvoiceStatus;
use App\Enum\Payment\Method as PaymentMethod;
use App\Models\AccountPayable;
use App\Models\AccountPayableInstallment;
use App\Models\AccountReceivable;
use App\Models\AccountReceivableInstallment;
use App\Models\AuditEntry;
use App\Models\BankStatementLine;
use App\Models\CashMovement;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\Invoice;
use App\Models\Partner;
use App\Models\User;
use App\Services\Financial\BankStatement\ImportBankStatementService;
use App\Services\Financial\BankStatement\ResolveBankStatementLineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankStatementImportAndReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Company $company;
    private FinancialAccount $financialAccount;
    private FinancialCategory $cashCategory;
    private FinancialCategory $payableCategory;
    private FinancialCategory $receivableCategory;
    private Partner $supplier;
    private Partner $customer;
    private ImportBankStatementService $importService;
    private ResolveBankStatementLineService $resolveService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importService = app(ImportBankStatementService::class);
        $this->resolveService = app(ResolveBankStatementLineService::class);
        $this->user = User::factory()->create();

        $this->company = Company::create([
            'name' => 'Empresa Extrato',
            'document_number' => '12345678000111',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $this->user->id,
        ]);

        $this->financialAccount = FinancialAccount::create([
            'company_id' => $this->company->id,
            'name' => 'Conta Corrente',
            'type' => FinancialAccountType::BANK->value,
            'opening_balance' => 1000,
            'is_active' => true,
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);

        $cashParent = FinancialCategory::create([
            'company_id' => $this->company->id,
            'name' => 'Operacional',
            'allow_cash_movement' => false,
            'is_active' => true,
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);

        $this->cashCategory = FinancialCategory::create([
            'company_id' => $this->company->id,
            'parent_id' => $cashParent->id,
            'name' => 'Diversos',
            'allow_cash_movement' => true,
            'is_active' => true,
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);

        $payableParent = FinancialCategory::create([
            'company_id' => $this->company->id,
            'name' => 'Despesas',
            'allow_payable' => false,
            'allow_cash_movement' => false,
            'is_active' => true,
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);

        $this->payableCategory = FinancialCategory::create([
            'company_id' => $this->company->id,
            'parent_id' => $payableParent->id,
            'name' => 'Fornecedores',
            'allow_payable' => true,
            'allow_cash_movement' => true,
            'is_active' => true,
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);

        $receivableParent = FinancialCategory::create([
            'company_id' => $this->company->id,
            'name' => 'Receitas',
            'allow_receivable' => false,
            'allow_cash_movement' => false,
            'is_active' => true,
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);

        $this->receivableCategory = FinancialCategory::create([
            'company_id' => $this->company->id,
            'parent_id' => $receivableParent->id,
            'name' => 'Clientes',
            'allow_receivable' => true,
            'allow_cash_movement' => true,
            'is_active' => true,
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);

        $this->supplier = Partner::create([
            'name' => 'Fornecedor OFX',
            'document_type' => 'CNPJ',
            'document_number' => '12345678000122',
            'created_by' => $this->user->id,
        ]);

        $this->customer = Partner::create([
            'name' => 'Cliente OFX',
            'document_type' => 'CPF',
            'document_number' => '12345678910',
            'created_by' => $this->user->id,
        ]);
    }

    public function test_imports_bradesco_ofx_generates_suggestions_and_replaces_previous_import(): void
    {
        $movement = CashMovement::create([
            'company_id' => $this->company->id,
            'financial_account_id' => $this->financialAccount->id,
            'financial_category_id' => $this->cashCategory->id,
            'direction' => CashMovementDirection::OUTFLOW->value,
            'transaction_date' => '2026-04-10',
            'amount' => 150,
            'description' => 'Pagamento fornecedor teste',
            'origin_type' => 'manual',
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);

        $ofx = $this->buildOfx('237', [
            [
                'type' => 'DEBIT',
                'date' => '20260410',
                'amount' => '-150.00',
                'fitid' => 'A1',
                'memo' => 'Pagamento fornecedor teste',
            ],
            [
                'type' => 'CREDIT',
                'date' => '20260411',
                'amount' => '75.00',
                'fitid' => 'A2',
                'memo' => 'Recebimento avulso',
            ],
        ]);

        $import = $this->importService->importFromString(
            $this->company->id,
            $this->financialAccount->id,
            $ofx,
            'extrato-bradesco.ofx',
            $this->user->id,
        );

        $this->assertNotNull($import, $this->importService->getMessageUser());
        $this->assertSame('Bradesco', data_get($import->metadata, 'institution_name'));
        $this->assertDatabaseCount('bank_statement_imports', 1);
        $this->assertDatabaseCount('bank_statement_lines', 2);
        $this->assertDatabaseHas('audit_entries', [
            'company_id' => $this->company->id,
            'auditable_type' => \App\Models\BankStatementImport::class,
            'auditable_id' => $import->id,
            'actor_user_id' => $this->user->id,
            'event' => 'bank_statement_import.imported',
            'action' => 'imported',
        ]);

        $firstLine = BankStatementLine::query()->where('external_id', 'A1')->first();
        $this->assertNotNull($firstLine);
        $this->assertSame('outflow', data_get($firstLine->metadata, 'direction'));
        $this->assertSame('cash_movement', data_get($firstLine->metadata, 'suggestions.0.origin_type'));
        $this->assertSame($movement->id, data_get($firstLine->metadata, 'suggestions.0.origin_id'));

        $replacement = $this->importService->importFromString(
            $this->company->id,
            $this->financialAccount->id,
            $ofx,
            'extrato-bradesco-reprocessado.ofx',
            $this->user->id,
        );

        $this->assertNotNull($replacement, $this->importService->getMessageUser());
        $this->assertSame($import->id, $replacement->id);
        $this->assertDatabaseCount('bank_statement_imports', 1);
        $this->assertDatabaseCount('bank_statement_lines', 2);
        $this->assertSame('extrato-bradesco-reprocessado.ofx', $replacement->file_name);
    }

    public function test_reconciles_line_with_existing_cash_movement(): void
    {
        $movement = CashMovement::create([
            'company_id' => $this->company->id,
            'financial_account_id' => $this->financialAccount->id,
            'financial_category_id' => $this->cashCategory->id,
            'direction' => CashMovementDirection::INFLOW->value,
            'transaction_date' => '2026-04-11',
            'amount' => 80,
            'description' => 'Recebimento eventual',
            'origin_type' => 'manual',
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);

        $import = $this->importService->importFromString(
            $this->company->id,
            $this->financialAccount->id,
            $this->buildOfx('756', [[
                'type' => 'CREDIT',
                'date' => '20260411',
                'amount' => '80.00',
                'fitid' => 'B1',
                'memo' => 'Recebimento eventual',
            ]]),
            'extrato-sicoob.ofx',
            $this->user->id,
        );

        $line = $import?->lines->first();
        $this->assertNotNull($line);

        $resolved = $this->resolveService->reconcileWithCashMovement($line, $movement->id, $this->user->id);

        $this->assertNotNull($resolved, $this->resolveService->getMessageUser());
        $this->assertSame('reconciled', $resolved->reconciliation_status->value);
        $this->assertSame($movement->id, $resolved->cash_movement_id);
        $this->assertSame('cash_movement', data_get($resolved->metadata, 'decision.type'));
        $this->assertDatabaseHas('audit_entries', [
            'company_id' => $this->company->id,
            'auditable_type' => \App\Models\BankStatementImport::class,
            'auditable_id' => $import->id,
            'event' => 'bank_statement_import.movement_reconciled',
            'action' => 'movement_reconciled',
        ]);
    }

    public function test_reconciles_outflow_with_payable_installment_and_creates_payment(): void
    {
        $payable = AccountPayable::create([
            'supplier_id' => $this->supplier->id,
            'company_id' => $this->company->id,
            'fiscal_document_id' => null,
            'sequence_number' => '01',
            'status' => AccountPayableStatus::PENDING->value,
            'due_date' => '2026-04-10',
            'paid_date' => null,
            'due_amount' => 110,
            'paid_amount' => 0,
            'paid' => false,
            'payment_method' => PaymentMethod::PIX->value,
            'description' => 'Compra de insumos',
        ]);

        $installment = AccountPayableInstallment::create([
            'account_payable_id' => $payable->id,
            'company_id' => $this->company->id,
            'sequence_number' => '01',
            'status' => AccountPayableStatus::PENDING->value,
            'due_date' => '2026-04-10',
            'original_amount' => 100,
            'interest_amount' => 0,
            'fine_amount' => 0,
            'discount_amount' => 0,
            'due_amount' => 100,
            'paid_amount' => 0,
            'balance_amount' => 100,
            'financial_category_id' => $this->payableCategory->id,
            'notes' => 'Fornecedor OFX',
        ]);

        $import = $this->importService->importFromString(
            $this->company->id,
            $this->financialAccount->id,
            $this->buildOfx('748', [[
                'type' => 'DEBIT',
                'date' => '20260410',
                'amount' => '-110.00',
                'fitid' => 'C1',
                'memo' => 'Fornecedor OFX compra de insumos',
            ]]),
            'extrato-sicredi.ofx',
            $this->user->id,
        );

        $line = $import?->lines->first();
        $resolved = $this->resolveService->reconcileWithPayableInstallment($line, $installment->id, [
            'payment_date' => '2026-04-10',
            'interest_amount' => 10,
            'fine_amount' => 0,
            'discount_amount' => 0,
        ], $this->user->id);

        $this->assertNotNull($resolved, $this->resolveService->getMessageUser());
        $this->assertSame('reconciled', $resolved->reconciliation_status->value);
        $this->assertSame(0.0, $installment->fresh()->balance_amount);
        $this->assertDatabaseHas('account_payable_installment_payments', [
            'account_payable_installment_id' => $installment->id,
            'financial_account_id' => $this->financialAccount->id,
        ]);
        $this->assertDatabaseHas('cash_movements', [
            'id' => $resolved->cash_movement_id,
            'financial_account_id' => $this->financialAccount->id,
        ]);
        $this->assertSame('account_payable_installment', data_get($resolved->metadata, 'decision.type'));
        $this->assertDatabaseHas('audit_entries', [
            'company_id' => $this->company->id,
            'auditable_type' => AccountPayable::class,
            'auditable_id' => $payable->id,
            'event' => 'account_payable.payment_registered',
            'action' => 'payment_registered',
        ]);
    }

    public function test_reconciles_inflow_with_receivable_installment_and_creates_receipt(): void
    {
        $invoice = Invoice::create([
            'customer_id' => $this->customer->id,
            'company_id' => $this->company->id,
            'invoice_number' => '123456',
            'invoice_date' => '2026-04-08',
            'status' => InvoiceStatus::CONFIRMED->value,
            'pending' => false,
            'confirmed' => true,
            'created_by' => $this->user->id,
        ]);

        $receivable = AccountReceivable::create([
            'customer_id' => $this->customer->id,
            'company_id' => $this->company->id,
            'invoice_id' => $invoice->id,
            'sequence_number' => '01',
            'status' => AccountReceivableStatus::PENDING->value,
            'due_date' => '2026-04-12',
            'paid_date' => null,
            'due_amount' => 95,
            'paid_amount' => 0,
            'paid' => false,
            'payment_method' => PaymentMethod::PIX->value,
            'description' => 'Servico tecnico',
        ]);

        $installment = AccountReceivableInstallment::create([
            'account_receivable_id' => $receivable->id,
            'company_id' => $this->company->id,
            'sequence_number' => '01',
            'status' => AccountReceivableStatus::PENDING->value,
            'due_date' => '2026-04-12',
            'original_amount' => 100,
            'interest_amount' => 0,
            'fine_amount' => 0,
            'discount_amount' => 0,
            'due_amount' => 100,
            'received_amount' => 0,
            'balance_amount' => 100,
            'financial_category_id' => $this->receivableCategory->id,
            'notes' => 'Cliente OFX',
        ]);

        $import = $this->importService->importFromString(
            $this->company->id,
            $this->financialAccount->id,
            $this->buildOfx('237', [[
                'type' => 'CREDIT',
                'date' => '20260412',
                'amount' => '95.00',
                'fitid' => 'D1',
                'memo' => 'Cliente OFX servico tecnico',
            ]]),
            'extrato-recebimento.ofx',
            $this->user->id,
        );

        $line = $import?->lines->first();
        $resolved = $this->resolveService->reconcileWithReceivableInstallment($line, $installment->id, [
            'payment_date' => '2026-04-12',
            'discount_amount' => 5,
        ], $this->user->id);

        $this->assertNotNull($resolved, $this->resolveService->getMessageUser());
        $this->assertSame('reconciled', $resolved->reconciliation_status->value);
        $this->assertSame(0.0, $installment->fresh()->balance_amount);
        $this->assertDatabaseHas('account_receivable_installment_payments', [
            'account_receivable_installment_id' => $installment->id,
            'financial_account_id' => $this->financialAccount->id,
        ]);
        $this->assertDatabaseHas('cash_movements', [
            'id' => $resolved->cash_movement_id,
            'financial_account_id' => $this->financialAccount->id,
        ]);
        $this->assertSame('account_receivable_installment', data_get($resolved->metadata, 'decision.type'));
        $this->assertDatabaseHas('audit_entries', [
            'company_id' => $this->company->id,
            'auditable_type' => AccountReceivable::class,
            'auditable_id' => $receivable->id,
            'event' => 'account_receivable.payment_registered',
            'action' => 'payment_registered',
        ]);
    }

    public function test_creates_manual_movement_when_no_candidate_is_selected(): void
    {
        $import = $this->importService->importFromString(
            $this->company->id,
            $this->financialAccount->id,
            $this->buildOfx('237', [[
                'type' => 'DEBIT',
                'date' => '20260415',
                'amount' => '-42.50',
                'fitid' => 'E1',
                'memo' => 'Despesa avulsa sem candidato',
            ]]),
            'extrato-manual.ofx',
            $this->user->id,
        );

        $line = $import?->lines->first();
        $resolved = $this->resolveService->createManualMovement($line, [
            'financial_category_id' => $this->cashCategory->id,
            'transaction_date' => '2026-04-15',
            'description' => 'Despesa avulsa conciliada manualmente',
        ], $this->user->id);

        $this->assertNotNull($resolved, $this->resolveService->getMessageUser());
        $this->assertSame('reconciled', $resolved->reconciliation_status->value);
        $movement = CashMovement::query()->find($resolved->cash_movement_id);
        $this->assertNotNull($movement);
        $this->assertSame('Despesa avulsa conciliada manualmente', $movement->description);
        $this->assertSame(42.5, (float) $movement->amount);
        $this->assertSame('manual', data_get($resolved->metadata, 'decision.type'));
        $this->assertDatabaseHas('audit_entries', [
            'company_id' => $this->company->id,
            'auditable_type' => \App\Models\BankStatementImport::class,
            'auditable_id' => $import->id,
            'event' => 'bank_statement_import.manual_movement_created',
            'action' => 'manual_movement_created',
        ]);
        $this->assertGreaterThanOrEqual(
            1,
            AuditEntry::query()
                ->where('auditable_type', CashMovement::class)
                ->where('auditable_id', $movement->id)
                ->where('event', 'cash_movement.created')
                ->count()
        );
    }

    /**
     * @param  array<int, array<string, string>>  $transactions
     */
    private function buildOfx(string $bankId, array $transactions): string
    {
        $body = collect($transactions)->map(function (array $transaction): string {
            return <<<OFX
<STMTTRN>
<TRNTYPE>{$transaction['type']}
<DTPOSTED>{$transaction['date']}120000[-3:BRT]
<TRNAMT>{$transaction['amount']}
<FITID>{$transaction['fitid']}
<MEMO>{$transaction['memo']}
</STMTTRN>
OFX;
        })->implode("\n");

        return <<<OFX
OFXHEADER:100
DATA:OFXSGML
VERSION:102
SECURITY:NONE
ENCODING:USASCII
CHARSET:1252
COMPRESSION:NONE
OLDFILEUID:NONE
NEWFILEUID:NONE

<OFX>
<BANKMSGSRSV1>
<STMTTRNRS>
<STMTRS>
<CURDEF>BRL
<BANKACCTFROM>
<BANKID>{$bankId}
<BRANCHID>1234
<ACCTID>12345-6
<ACCTTYPE>CHECKING
</BANKACCTFROM>
<BANKTRANLIST>
<DTSTART>20260401000000[-3:BRT]
<DTEND>20260430000000[-3:BRT]
{$body}
</BANKTRANLIST>
<LEDGERBAL>
<BALAMT>1200.00
<DTASOF>20260430000000[-3:BRT]
</LEDGERBAL>
</STMTRS>
</STMTTRNRS>
</BANKMSGSRSV1>
</OFX>
OFX;
    }
}
