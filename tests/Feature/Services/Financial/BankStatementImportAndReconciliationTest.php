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
use App\Models\AccountPayableInstallmentPayment;
use App\Models\AccountReceivable;
use App\Models\AccountReceivableInstallment;
use App\Models\AccountReceivableInstallmentPayment;
use App\Models\AuditEntry;
use App\Models\BankStatementImport;
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

    public function test_imports_bradesco_ofx_generates_suggestions_and_synchronizes_an_existing_import(): void
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
            $ofx."\n",
            'extrato-bradesco.ofx',
            $this->user->id,
        );

        $this->assertNotNull($import, $this->importService->getMessageUser());
        $this->assertSame('Bradesco', data_get($import->metadata, 'institution_name'));
        $this->assertDatabaseCount('bank_statement_imports', 1);
        $this->assertDatabaseCount('bank_statement_lines', 2);
        $this->assertDatabaseHas('audit_entries', [
            'company_id' => $this->company->id,
            'auditable_type' => BankStatementImport::class,
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
        $initialLineIds = $import->lines->pluck('id')->sort()->values()->all();

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
        $this->assertDatabaseCount('bank_statement_import_runs', 2);
        $this->assertSame(2, $replacement->runs()->latest('id')->first()?->summary['preserved']);
        $this->assertSame($initialLineIds, $replacement->lines->pluck('id')->sort()->values()->all());

        $this->assertNull($this->importService->importFromString(
            $this->company->id,
            $this->financialAccount->id,
            $ofx."\n",
            'extrato-bradesco-duplicado.ofx',
            $this->user->id,
        ));
        $this->assertTrue($this->importService->hasError());
        $this->assertDatabaseCount('bank_statement_import_runs', 2);
    }

    public function test_imports_banco_inter_ofx(): void
    {
        $ofx = <<<'OFX'
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
<SIGNONMSGSRSV1>
<SONRS>
<FI>
<ORG>Banco Intermedium S/A</ORG>
<FID>077</FID>
</FI>
</SONRS>
</SIGNONMSGSRSV1>
<BANKMSGSRSV1>
<STMTTRNRS>
<STMTRS>
<CURDEF>BRL</CURDEF>
<BANKACCTFROM>
<BANKID>077</BANKID>
<BRANCHID>0001-9</BRANCHID>
<ACCTID>31957099</ACCTID>
<ACCTTYPE>CHECKING</ACCTTYPE>
</BANKACCTFROM>
<BANKTRANLIST>
<DTSTART>20260630</DTSTART>
<DTEND>20260730</DTEND>
<STMTTRN>
<TRNTYPE>PAYMENT</TRNTYPE>
<DTPOSTED>20260727</DTPOSTED>
<TRNAMT>-60.00</TRNAMT>
<FITID>202607270771</FITID>
<CHECKNUM>077</CHECKNUM>
<REFNUM>077</REFNUM>
<MEMO>Pix enviado: "Cp :00000000-Carlos Giovani Deitos"</MEMO>
<NAME>Carlos Giovani Deitos</NAME>
</STMTTRN>
<STMTTRN>
<TRNTYPE>CREDIT</TRNTYPE>
<DTPOSTED>20260722</DTPOSTED>
<TRNAMT>24.00</TRNAMT>
<FITID>202607220772</FITID>
<CHECKNUM>077</CHECKNUM>
<REFNUM>077</REFNUM>
<MEMO>Pix recebido: "00019 58037349 ROBSON VAZ"</MEMO>
<NAME>Robson Luiz De Ramos Vaz</NAME>
</STMTTRN>
</BANKTRANLIST>
</STMTRS>
</STMTTRNRS>
</BANKMSGSRSV1>
</OFX>
OFX;

        $import = $this->importService->importFromString(
            $this->company->id,
            $this->financialAccount->id,
            $ofx."\n",
            'extrato-inter.ofx',
            $this->user->id,
        );

        $this->assertNotNull($import, $this->importService->getMessageUser());
        $this->assertSame('Banco Inter', data_get($import->metadata, 'institution_name'));
        $this->assertSame('077', data_get($import->metadata, 'bank_id'));
        $this->assertSame(2, $import->lines->count());
        $this->assertTrue($import->lines->contains(
            fn (BankStatementLine $line): bool => data_get($line->metadata, 'direction') === 'outflow'
        ));
    }

    public function test_reimport_preserves_a_reconciled_line_when_the_bank_transaction_is_unchanged(): void
    {
        $movement = CashMovement::create([
            'company_id' => $this->company->id,
            'financial_account_id' => $this->financialAccount->id,
            'financial_category_id' => $this->cashCategory->id,
            'direction' => CashMovementDirection::INFLOW->value,
            'transaction_date' => '2026-04-11',
            'amount' => 75,
            'description' => 'Recebimento avulso',
            'origin_type' => 'manual',
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);
        $ofx = $this->buildOfx('237', [[
            'type' => 'CREDIT',
            'date' => '20260411',
            'amount' => '75.00',
            'fitid' => 'RECONCILED-1',
            'memo' => 'Recebimento avulso',
        ]]);
        $import = $this->importService->importFromString(
            $this->company->id,
            $this->financialAccount->id,
            $ofx."\n",
            'extrato-original.ofx',
            $this->user->id,
        );
        $line = $import?->lines()->sole();

        $this->assertNotNull($line);
        $this->assertNotNull($this->resolveService->reconcileWithCashMovement($line, $movement->id, $this->user->id));

        $reimport = $this->importService->importFromString(
            $this->company->id,
            $this->financialAccount->id,
            $ofx,
            'extrato-reimportado.ofx',
            $this->user->id,
        );
        $preservedLine = BankStatementLine::query()->findOrFail($line->id);

        $this->assertNotNull($reimport, $this->importService->getMessageUser());
        $this->assertSame('reconciled', $preservedLine->reconciliation_status->value);
        $this->assertSame($movement->id, $preservedLine->cash_movement_id);
        $this->assertSame(1, $reimport->lines()->count());
        $this->assertSame(1, $reimport->runs()->latest('id')->first()?->summary['preserved']);
    }

    public function test_reimport_marks_a_changed_reconciled_line_for_review_without_removing_its_link(): void
    {
        $movement = CashMovement::create([
            'company_id' => $this->company->id,
            'financial_account_id' => $this->financialAccount->id,
            'financial_category_id' => $this->cashCategory->id,
            'direction' => CashMovementDirection::INFLOW->value,
            'transaction_date' => '2026-04-11',
            'amount' => 75,
            'description' => 'Recebimento avulso',
            'origin_type' => 'manual',
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);
        $originalOfx = $this->buildOfx('237', [[
            'type' => 'CREDIT',
            'date' => '20260411',
            'amount' => '75.00',
            'fitid' => 'DIVERGENT-1',
            'memo' => 'Recebimento avulso',
        ]]);
        $import = $this->importService->importFromString(
            $this->company->id,
            $this->financialAccount->id,
            $originalOfx,
            'extrato-original.ofx',
            $this->user->id,
        );
        $line = $import?->lines()->sole();

        $this->assertNotNull($line);
        $this->assertNotNull($this->resolveService->reconcileWithCashMovement($line, $movement->id, $this->user->id));

        $changedOfx = $this->buildOfx('237', [[
            'type' => 'CREDIT',
            'date' => '20260411',
            'amount' => '80.00',
            'fitid' => 'DIVERGENT-1',
            'memo' => 'Recebimento avulso corrigido',
        ]]);
        $reimport = $this->importService->importFromString(
            $this->company->id,
            $this->financialAccount->id,
            $changedOfx,
            'extrato-corrigido.ofx',
            $this->user->id,
        );
        $reviewLine = BankStatementLine::query()->findOrFail($line->id);

        $this->assertNotNull($reimport, $this->importService->getMessageUser());
        $this->assertSame('needs_review', $reviewLine->reconciliation_status->value);
        $this->assertSame($movement->id, $reviewLine->cash_movement_id);
        $this->assertSame(80.0, (float) $reviewLine->amount);
        $this->assertNotNull($reviewLine->needs_review_at);
        $this->assertSame('Dados bancários divergentes na reimportação.', $reviewLine->review_reason);
        $this->assertSame(1, $reimport->runs()->latest('id')->first()?->summary['needs_review']);
    }

    public function test_reimport_keeps_a_missing_reconciled_line_unchanged_when_the_file_may_be_partial(): void
    {
        $movement = CashMovement::create([
            'company_id' => $this->company->id,
            'financial_account_id' => $this->financialAccount->id,
            'financial_category_id' => $this->cashCategory->id,
            'direction' => CashMovementDirection::INFLOW->value,
            'transaction_date' => '2026-04-11',
            'amount' => 75,
            'description' => 'Recebimento avulso',
            'origin_type' => 'manual',
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);
        $originalOfx = $this->buildOfx('237', [
            [
                'type' => 'CREDIT',
                'date' => '20260411',
                'amount' => '75.00',
                'fitid' => 'MISSING-1',
                'memo' => 'Recebimento avulso',
            ],
            [
                'type' => 'DEBIT',
                'date' => '20260412',
                'amount' => '-20.00',
                'fitid' => 'MISSING-2',
                'memo' => 'Despesa mantida',
            ],
        ]);
        $import = $this->importService->importFromString(
            $this->company->id,
            $this->financialAccount->id,
            $originalOfx,
            'extrato-original.ofx',
            $this->user->id,
        );
        $line = $import?->lines()->where('external_id', 'MISSING-1')->sole();

        $this->assertNotNull($line);
        $this->assertNotNull($this->resolveService->reconcileWithCashMovement($line, $movement->id, $this->user->id));

        $reimport = $this->importService->importFromString(
            $this->company->id,
            $this->financialAccount->id,
            $this->buildOfx('237', [[
                'type' => 'DEBIT',
                'date' => '20260412',
                'amount' => '-20.00',
                'fitid' => 'MISSING-2',
                'memo' => 'Despesa mantida',
            ]]),
            'extrato-parcial.ofx',
            $this->user->id,
        );
        $preservedLine = BankStatementLine::query()->findOrFail($line->id);

        $this->assertNotNull($reimport, $this->importService->getMessageUser());
        $this->assertSame('reconciled', $preservedLine->reconciliation_status->value);
        $this->assertSame($movement->id, $preservedLine->cash_movement_id);
        $this->assertNull($preservedLine->review_reason);
        $this->assertSame(2, $reimport->lines()->count());
        $this->assertSame(1, $reimport->runs()->latest('id')->first()?->summary['missing_from_file']);
    }

    public function test_imports_windows_1252_ofx_with_accented_metadata(): void
    {
        $ofx = mb_convert_encoding($this->buildOfx('748', [[
            'type' => 'DEBIT',
            'date' => '20260412',
            'amount' => '-35.50',
            'fitid' => 'SICREDI-1',
            'memo' => 'Débito manutenção cartão',
        ]]), 'Windows-1252', 'UTF-8');

        $import = $this->importService->importFromString(
            $this->company->id,
            $this->financialAccount->id,
            $ofx,
            'extrato-sicredi-1252.ofx',
            $this->user->id,
        );

        $this->assertNotNull($import, $this->importService->getMessageUser());
        $this->assertSame('Sicredi', data_get($import->metadata, 'institution_name'));
        $this->assertSame('Débito manutenção cartão', $import->lines->first()->description);
        $this->assertSame('Débito manutenção cartão', data_get($import->lines->first()->metadata, 'description'));
        $this->assertSame('Débito manutenção cartão', data_get($import->lines->first()->metadata, 'raw.memo'));
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
            'auditable_type' => BankStatementImport::class,
            'auditable_id' => $import->id,
            'event' => 'bank_statement_import.movement_reconciled',
            'action' => 'movement_reconciled',
        ]);
    }

    public function test_it_rejects_incompatible_and_reversed_movements(): void
    {
        $line = $this->importLine('CREDIT', '100.00', 'ELIGIBILITY-1');
        $outflow = $this->createCashMovement(CashMovementDirection::OUTFLOW, 100);
        $reversedInflow = $this->createCashMovement(CashMovementDirection::INFLOW, 100, ['reversed_at' => now()]);

        $this->assertNull($this->resolveService->reconcileWithCashMovement($line, $outflow->id, $this->user->id));
        $this->assertArrayHasKey('cash_movement_id', $this->resolveService->getErrors());
        $this->assertNull($this->resolveService->reconcileWithCashMovement($line, $reversedInflow->id, $this->user->id));
        $this->assertArrayHasKey('cash_movement_id', $this->resolveService->getErrors());
        $this->assertSame('pending', $line->fresh()->reconciliation_status->value);
    }

    public function test_it_requires_a_reason_to_reconcile_outside_the_configured_margin(): void
    {
        $line = $this->importLine('CREDIT', '100.00', 'EXCEPTION-1');
        $movement = $this->createCashMovement(CashMovementDirection::INFLOW, 99);

        $this->assertNull($this->resolveService->reconcileWithCashMovement($line, $movement->id, $this->user->id));
        $this->assertArrayHasKey('exception_reason', $this->resolveService->getErrors());

        $resolved = $this->resolveService->reconcileWithCashMovement($line, $movement->id, $this->user->id, [
            'exception_reason' => 'Tarifa bancária descontada no crédito.',
        ]);

        $this->assertNotNull($resolved, $this->resolveService->getMessageUser());
        $this->assertNotEmpty(data_get($resolved->metadata, 'decision.eligibility_exceptions'));
    }

    public function test_only_one_statement_line_can_claim_the_same_cash_movement(): void
    {
        $movement = $this->createCashMovement(CashMovementDirection::INFLOW, 100);
        $import = $this->importService->importFromString(
            $this->company->id,
            $this->financialAccount->id,
            $this->buildOfx('237', [
                ['type' => 'CREDIT', 'date' => '20260411', 'amount' => '100.00', 'fitid' => 'MOVEMENT-CLAIM-1', 'memo' => 'Primeiro crédito'],
                ['type' => 'CREDIT', 'date' => '20260411', 'amount' => '100.00', 'fitid' => 'MOVEMENT-CLAIM-2', 'memo' => 'Segundo crédito'],
            ]),
            'extrato-disputa-movimento.ofx',
            $this->user->id,
        );
        $lines = $import?->lines()->orderBy('id')->get();
        $firstLine = $lines?->first();
        $secondLine = $lines?->last();

        $this->assertNotNull($firstLine);
        $this->assertNotNull($secondLine);
        $this->assertNotNull($this->resolveService->reconcileWithCashMovement($firstLine, $movement->id, $this->user->id));
        $this->assertNull($this->resolveService->reconcileWithCashMovement($secondLine, $movement->id, $this->user->id));
        $this->assertArrayHasKey('cash_movement_id', $this->resolveService->getErrors());
        $this->assertSame('pending', $secondLine->fresh()->reconciliation_status->value);
    }

    public function test_it_reopens_a_existing_movement_reconciliation_as_pending(): void
    {
        $line = $this->importLine('CREDIT', '100.00', 'REVERSE-EXISTING');
        $movement = $this->createCashMovement(CashMovementDirection::INFLOW, 100);
        $this->assertNotNull($this->resolveService->reconcileWithCashMovement($line, $movement->id, $this->user->id));

        $reversed = $this->resolveService->reverseReconciliation($line, $this->user->id, 'Vínculo selecionado incorretamente.');

        $this->assertNotNull($reversed, $this->resolveService->getMessageUser());
        $this->assertSame('pending', $reversed->reconciliation_status->value);
        $this->assertNull($reversed->cash_movement_id);
        $this->assertDatabaseHas('cash_movements', ['id' => $movement->id]);
    }

    public function test_it_resolves_a_review_by_reopening_a_line_without_financial_effect(): void
    {
        $line = $this->importLine('CREDIT', '100.00', 'REVIEW-REOPEN');
        $line->update([
            'reconciliation_status' => 'needs_review',
            'needs_review_at' => now(),
            'review_reason' => 'Dados bancários divergentes.',
        ]);

        $resolved = $this->resolveService->resolveReview($line, $this->user->id, 'reopen', 'Divergência confirmada sem efeito financeiro.');

        $this->assertNotNull($resolved, $this->resolveService->getMessageUser());
        $this->assertSame('pending', $resolved->reconciliation_status->value);
        $this->assertNull($resolved->review_reason);
    }

    public function test_it_resolves_a_review_by_keeping_the_existing_financial_effect(): void
    {
        $line = $this->importLine('CREDIT', '100.00', 'REVIEW-KEEP');
        $movement = $this->createCashMovement(CashMovementDirection::INFLOW, 100);
        $this->assertNotNull($this->resolveService->reconcileWithCashMovement($line, $movement->id, $this->user->id));
        $line->fresh()->update([
            'reconciliation_status' => 'needs_review',
            'needs_review_at' => now(),
            'review_reason' => 'Arquivo reimportado com descrição distinta.',
        ]);

        $resolved = $this->resolveService->resolveReview($line, $this->user->id, 'keep', 'Movimento e valor conferidos no banco.');

        $this->assertNotNull($resolved, $this->resolveService->getMessageUser());
        $this->assertSame('reconciled', $resolved->reconciliation_status->value);
        $this->assertSame($movement->id, $resolved->cash_movement_id);
    }

    public function test_an_ignored_line_must_be_reopened_before_receiving_a_new_resolution(): void
    {
        $line = $this->importLine('CREDIT', '100.00', 'REOPEN-1');
        $movement = $this->createCashMovement(CashMovementDirection::INFLOW, 100);

        $this->assertNotNull($this->resolveService->ignore($line, $this->user->id, 'Lançamento em análise.'));
        $this->assertNull($this->resolveService->reconcileWithCashMovement($line, $movement->id, $this->user->id));
        $this->assertNull($this->resolveService->reopenIgnored($line, $this->user->id, ''));
        $this->assertNotNull($this->resolveService->reopenIgnored($line, $this->user->id, 'Análise concluída.'));

        $resolved = $this->resolveService->reconcileWithCashMovement($line, $movement->id, $this->user->id);

        $this->assertNotNull($resolved, $this->resolveService->getMessageUser());
        $this->assertSame('reconciled', $resolved->reconciliation_status->value);
        $this->assertDatabaseHas('audit_entries', [
            'event' => 'bank_statement_import.line_reopened',
            'auditable_id' => $line->bank_statement_import_id,
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
            'counterparty_partner_id' => $this->supplier->id,
        ], $this->user->id);

        $this->assertNotNull($resolved, $this->resolveService->getMessageUser());
        $this->assertSame('reconciled', $resolved->reconciliation_status->value);
        $movement = CashMovement::query()->find($resolved->cash_movement_id);
        $this->assertNotNull($movement);
        $this->assertSame('Despesa avulsa conciliada manualmente', $movement->description);
        $this->assertSame(42.5, (float) $movement->amount);
        $this->assertSame($this->supplier->id, $movement->counterparty_partner_id);
        $this->assertSame('manual', data_get($resolved->metadata, 'decision.type'));
        $this->assertDatabaseHas('audit_entries', [
            'company_id' => $this->company->id,
            'auditable_type' => BankStatementImport::class,
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

    public function test_it_removes_a_manual_movement_when_reversing_its_reconciliation(): void
    {
        $line = $this->importLine('DEBIT', '-42.50', 'REVERSE-MANUAL');
        $resolved = $this->resolveService->createManualMovement($line, [
            'financial_category_id' => $this->cashCategory->id,
            'transaction_date' => '2026-04-11',
            'description' => 'Movimento manual a desfazer',
        ], $this->user->id);
        $this->assertNotNull($resolved, $this->resolveService->getMessageUser());

        $reversed = $this->resolveService->reverseReconciliation($resolved, $this->user->id, 'Lançamento manual indevido.');

        $this->assertNotNull($reversed, $this->resolveService->getMessageUser());
        $this->assertSame('reversed', $reversed->reconciliation_status->value);
        $this->assertNull($reversed->cash_movement_id);
        $this->assertDatabaseMissing('cash_movements', ['id' => $resolved->cash_movement_id]);
    }

    public function test_it_reverses_a_payable_reconciliation_and_restores_the_installment_balance(): void
    {
        $installment = $this->createPayableInstallmentForRollback();
        $line = $this->importLine('DEBIT', '-100.00', 'REVERSE-PAYABLE');
        $resolved = $this->resolveService->reconcileWithPayableInstallment($line, $installment->id, [
            'payment_date' => '2026-04-11',
        ], $this->user->id);
        $this->assertNotNull($resolved, $this->resolveService->getMessageUser());

        $reversed = $this->resolveService->reverseReconciliation($resolved, $this->user->id, 'Pagamento conciliado indevidamente.');

        $this->assertNotNull($reversed, $this->resolveService->getMessageUser());
        $this->assertSame('reversed', $reversed->reconciliation_status->value);
        $this->assertSame(100.0, $installment->fresh()->balance_amount);
        $this->assertDatabaseMissing('account_payable_installment_payments', [
            'account_payable_installment_id' => $installment->id,
        ]);
    }

    public function test_it_reverses_a_receivable_reconciliation_and_restores_the_installment_balance(): void
    {
        $installment = $this->createReceivableInstallmentForRollback();
        $line = $this->importLine('CREDIT', '100.00', 'REVERSE-RECEIVABLE');
        $resolved = $this->resolveService->reconcileWithReceivableInstallment($line, $installment->id, [
            'payment_date' => '2026-04-11',
        ], $this->user->id);
        $this->assertNotNull($resolved, $this->resolveService->getMessageUser());

        $reversed = $this->resolveService->reverseReconciliation($resolved, $this->user->id, 'Recebimento conciliado indevidamente.');

        $this->assertNotNull($reversed, $this->resolveService->getMessageUser());
        $this->assertSame('reversed', $reversed->reconciliation_status->value);
        $this->assertSame(100.0, $installment->fresh()->balance_amount);
        $this->assertDatabaseMissing('account_receivable_installment_payments', [
            'account_receivable_installment_id' => $installment->id,
        ]);
    }

    public function test_it_rolls_back_a_payable_payment_when_the_statement_link_is_rejected(): void
    {
        $installment = $this->createPayableInstallmentForRollback();
        $line = $this->importLine('DEBIT', '-100.00', 'PAYABLE-ROLLBACK');

        $resolved = $this->resolveService->reconcileWithPayableInstallment($line, $installment->id, [
            'payment_date' => '2026-05-11',
        ], $this->user->id);

        $this->assertNull($resolved);
        $this->assertSame(100.0, $installment->fresh()->balance_amount);
        $this->assertDatabaseMissing('account_payable_installment_payments', [
            'account_payable_installment_id' => $installment->id,
        ]);
        $this->assertDatabaseMissing('cash_movements', [
            'origin_type' => AccountPayableInstallmentPayment::class,
        ]);
        $this->assertSame('pending', $line->fresh()->reconciliation_status->value);
    }

    public function test_it_rolls_back_a_receivable_payment_when_the_statement_link_is_rejected(): void
    {
        $installment = $this->createReceivableInstallmentForRollback();
        $line = $this->importLine('CREDIT', '100.00', 'RECEIVABLE-ROLLBACK');

        $resolved = $this->resolveService->reconcileWithReceivableInstallment($line, $installment->id, [
            'payment_date' => '2026-05-11',
        ], $this->user->id);

        $this->assertNull($resolved);
        $this->assertSame(100.0, $installment->fresh()->balance_amount);
        $this->assertDatabaseMissing('account_receivable_installment_payments', [
            'account_receivable_installment_id' => $installment->id,
        ]);
        $this->assertDatabaseMissing('cash_movements', [
            'origin_type' => AccountReceivableInstallmentPayment::class,
        ]);
        $this->assertSame('pending', $line->fresh()->reconciliation_status->value);
    }

    public function test_it_rolls_back_a_manual_movement_when_the_statement_link_is_rejected(): void
    {
        $line = $this->importLine('DEBIT', '-42.50', 'MANUAL-ROLLBACK');

        $resolved = $this->resolveService->createManualMovement($line, [
            'financial_category_id' => $this->cashCategory->id,
            'transaction_date' => '2026-05-11',
            'description' => 'Movimento que deve ser desfeito',
        ], $this->user->id);

        $this->assertNull($resolved);
        $this->assertDatabaseMissing('cash_movements', [
            'description' => 'Movimento que deve ser desfeito',
        ]);
        $this->assertSame('pending', $line->fresh()->reconciliation_status->value);
    }

    private function createPayableInstallmentForRollback(): AccountPayableInstallment
    {
        $payable = AccountPayable::create([
            'supplier_id' => $this->supplier->id,
            'company_id' => $this->company->id,
            'sequence_number' => 'rollback-payable',
            'status' => AccountPayableStatus::PENDING->value,
            'due_date' => '2026-04-10',
            'due_amount' => 100,
            'paid_amount' => 0,
            'paid' => false,
            'payment_method' => PaymentMethod::PIX->value,
            'description' => 'Pagamento sujeito a rollback',
        ]);

        return AccountPayableInstallment::create([
            'account_payable_id' => $payable->id,
            'company_id' => $this->company->id,
            'sequence_number' => '01',
            'status' => AccountPayableStatus::PENDING->value,
            'due_date' => '2026-04-10',
            'original_amount' => 100,
            'due_amount' => 100,
            'paid_amount' => 0,
            'balance_amount' => 100,
            'financial_category_id' => $this->payableCategory->id,
        ]);
    }

    private function createReceivableInstallmentForRollback(): AccountReceivableInstallment
    {
        $invoice = Invoice::create([
            'customer_id' => $this->customer->id,
            'company_id' => $this->company->id,
            'invoice_number' => 'rollback-receivable',
            'invoice_date' => '2026-04-10',
            'status' => InvoiceStatus::CONFIRMED->value,
            'pending' => false,
            'confirmed' => true,
            'created_by' => $this->user->id,
        ]);
        $receivable = AccountReceivable::create([
            'customer_id' => $this->customer->id,
            'company_id' => $this->company->id,
            'invoice_id' => $invoice->id,
            'status' => AccountReceivableStatus::PENDING->value,
            'due_date' => '2026-04-10',
            'due_amount' => 100,
            'paid_amount' => 0,
            'paid' => false,
            'payment_method' => PaymentMethod::PIX->value,
            'description' => 'Recebimento sujeito a rollback',
        ]);

        return AccountReceivableInstallment::create([
            'account_receivable_id' => $receivable->id,
            'company_id' => $this->company->id,
            'sequence_number' => '01',
            'status' => AccountReceivableStatus::PENDING->value,
            'due_date' => '2026-04-10',
            'original_amount' => 100,
            'due_amount' => 100,
            'received_amount' => 0,
            'balance_amount' => 100,
            'financial_category_id' => $this->receivableCategory->id,
        ]);
    }

    private function importLine(string $type, string $amount, string $fitid): BankStatementLine
    {
        $import = $this->importService->importFromString(
            $this->company->id,
            $this->financialAccount->id,
            $this->buildOfx('237', [[
                'type' => $type,
                'date' => '20260411',
                'amount' => $amount,
                'fitid' => $fitid,
                'memo' => 'Lançamento de teste',
            ]]),
            "{$fitid}.ofx",
            $this->user->id,
        );

        return $import?->lines()->sole() ?? throw new \RuntimeException('Não foi possível importar a linha de teste.');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createCashMovement(CashMovementDirection $direction, float $amount, array $attributes = []): CashMovement
    {
        return CashMovement::create([
            'company_id' => $this->company->id,
            'financial_account_id' => $this->financialAccount->id,
            'financial_category_id' => $this->cashCategory->id,
            'direction' => $direction->value,
            'transaction_date' => '2026-04-11',
            'amount' => $amount,
            'description' => 'Movimento de teste',
            'origin_type' => 'manual',
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
            ...$attributes,
        ]);
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
