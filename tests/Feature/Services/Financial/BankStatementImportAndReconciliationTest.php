<?php

namespace Tests\Feature\Services\Financial;

use App\Enum\AccountPayable\Status as AccountPayableStatus;
use App\Enum\AccountReceivable\Status as AccountReceivableStatus;
use App\Enum\Financial\CashMovementDirection;
use App\Enum\Financial\FinancialAccountType;
use App\Enum\Invoice\Status as InvoiceStatus;
use App\Enum\Payment\Method as PaymentMethod;
use App\Filament\Clusters\Financial\Resources\BankStatementImports\Pages\ViewBankStatementImport;
use App\Filament\Clusters\Financial\Resources\BankStatementImports\RelationManagers\LinesRelationManager;
use App\Filament\Clusters\Financial\Resources\BankStatementLines\Pages\ListBankStatementLines;
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
use App\Services\Company\CompanyOperationalResetService;
use App\Services\Financial\BankStatement\BankStatementMovementEligibilityService;
use App\Services\Financial\BankStatement\GetBankReconciliationSuggestions;
use App\Services\Financial\BankStatement\ImportBankStatementService;
use App\Services\Financial\BankStatement\ReconcileBankStatementGroupService;
use App\Services\Financial\BankStatement\ResolveBankStatementLineService;
use App\Services\Financial\BankStatement\SearchBankReconciliationCandidates;
use App\Services\Financial\BankStatement\SuggestBankStatementMatchesService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_it_audits_a_failed_reconciliation_reversal(): void
    {
        $line = $this->importLine('CREDIT', '100.00', 'REVERSE-FAILED');

        $reversed = $this->resolveService->reverseReconciliation(
            $line,
            $this->user->id,
            'Tentativa de desfazimento para validar o erro.',
        );

        $this->assertNull($reversed);
        $this->assertStringContainsString(
            'Somente linhas conciliadas podem ter a conciliação desfeita.',
            $this->resolveService->getMessageUser(),
        );
        $this->assertDatabaseHas('audit_entries', [
            'company_id' => $this->company->id,
            'auditable_type' => BankStatementImport::class,
            'auditable_id' => $line->bank_statement_import_id,
            'event' => 'bank_statement_import.reconciliation_reversal_failed',
            'action' => 'reconciliation_reversal_failed',
        ]);
        $this->assertSame(
            'pending',
            $line->fresh()->reconciliation_status->value,
        );
    }

    public function test_it_explains_when_a_receivable_payment_from_reconciliation_is_missing(): void
    {
        $line = $this->importLine('CREDIT', '100.00', 'REVERSE-MISSING-PAYMENT');
        $movement = $this->createCashMovement(CashMovementDirection::INFLOW, 100, [
            'origin_type' => AccountReceivableInstallmentPayment::class,
            'origin_id' => 233,
        ]);
        $line->update([
            'cash_movement_id' => $movement->id,
            'reconciliation_status' => 'reconciled',
            'reconciled_at' => now(),
            'metadata' => array_merge($line->metadata ?? [], [
                'decision' => [
                    'type' => 'account_receivable_installment',
                    'payment_id' => 233,
                ],
            ]),
        ]);

        $reversed = $this->resolveService->reverseReconciliation(
            $line,
            $this->user->id,
            'Baixa ausente para validar o diagnóstico.',
        );

        $this->assertNull($reversed);
        $this->assertStringContainsString(
            'Recebimento da conta a receber não encontrado (ID 233).',
            $this->resolveService->getMessageUser(),
        );
        $this->assertSame('reconciled', $line->fresh()->reconciliation_status->value);
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
        $this->assertSame('pending', $reversed->reconciliation_status->value);
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
        $this->assertSame('pending', $reversed->reconciliation_status->value);
        $this->assertNull($reversed->cash_movement_id);
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
        $this->assertSame('pending', $reversed->reconciliation_status->value);
        $this->assertNull($reversed->cash_movement_id);
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

    public static function reconciliationTypes(): array
    {
        return ['receber' => ['receivable', 'CREDIT', '100.00'], 'pagar' => ['payable', 'DEBIT', '-100.00']];
    }

    #[DataProvider('reconciliationTypes')]
    public function test_group_distributes_one_bank_line_between_partial_installments_and_reverses_atomically(string $type, string $bankType, string $amount): void
    {
        $first = $type === 'receivable' ? $this->createReceivableInstallmentForRollback() : $this->createPayableInstallmentForRollback();
        $second = $first->replicate();
        $second->sequence_number = '02';
        $second->save();
        $line = $this->importLine($bankType, $amount, 'group-partial');
        app(ReconcileBankStatementGroupService::class)->reconcile($this->company->id, [$line->id], $type, [
            ['id' => $first->id, 'amount' => 60], ['id' => $second->id, 'amount' => 40],
        ], $this->user->id);

        $this->assertEquals(40, $first->fresh()->balance_amount);
        $this->assertEquals(60, $second->fresh()->balance_amount);
        $this->assertCount(2, $line->fresh()->cashMovements);
        $this->assertSame(10000, (int) $line->cashMovements()->sum('bank_statement_allocations.amount'));
        $this->assertSame(2, CashMovement::query()->reconciled()->count());
        $this->assertNotNull($this->resolveService->reverseReconciliation($line->fresh(), $this->user->id, 'Distribuição incorreta'));
        $this->assertEquals(100, $first->fresh()->balance_amount);
        $this->assertEquals(100, $second->fresh()->balance_amount);
        $this->assertSame('pending', $line->fresh()->reconciliation_status->value);
        $this->assertDatabaseCount('bank_statement_allocations', 0);
    }

    #[DataProvider('reconciliationTypes')]
    public function test_group_settles_one_installment_using_bank_dates_of_two_lines(string $type, string $bankType, string $amount): void
    {
        $installment = $type === 'receivable' ? $this->createReceivableInstallmentForRollback() : $this->createPayableInstallmentForRollback();
        $line = $this->importLine($bankType, $type === 'receivable' ? '40.00' : '-40.00', 'group-split');
        $other = $this->duplicatePendingLine($line, 60);
        $other->update(['transaction_date' => '2026-04-12']);
        app(ReconcileBankStatementGroupService::class)->reconcile($this->company->id, [$line->id, $other->id], $type, [['id' => $installment->id, 'amount' => 100]], $this->user->id);
        $this->assertEquals(0, $installment->fresh()->balance_amount);
        $this->assertEquals(40, $line->fresh()->cashMovements->sole()->amount);
        $this->assertEquals(60, $other->fresh()->cashMovements->sole()->amount);
        $this->assertSame('2026-04-11', $line->cashMovements->sole()->transaction_date->toDateString());
        $this->assertSame('2026-04-12', $other->cashMovements->sole()->transaction_date->toDateString());
        $this->assertNotNull($this->resolveService->reverseReconciliation($other->fresh(), $this->user->id, 'Desfazer o grupo'));
        $this->assertEquals(100, $installment->fresh()->balance_amount);
        $this->assertSame('pending', $line->fresh()->reconciliation_status->value);
        $this->assertSame('pending', $other->fresh()->reconciliation_status->value);
    }

    #[DataProvider('reconciliationTypes')]
    public function test_group_links_existing_movements_in_both_directions_without_changing_cash(string $type, string $bankType, string $amount): void
    {
        $line = $this->importLine($bankType, $amount, 'group-existing');
        $direction = $line->direction();
        $first = $this->createCashMovement($direction, 40);
        $second = $this->createCashMovement($direction, 60);
        $service = app(ReconcileBankStatementGroupService::class);
        $service->reconcile($this->company->id, [$line->id], 'cash_movement', [['id' => $first->id], ['id' => $second->id]], $this->user->id);
        $this->assertDatabaseCount('cash_movements', 2);
        $this->assertTrue($first->hasStatementLinks());
        $this->assertNotNull($this->resolveService->reverseReconciliation($line->fresh(), $this->user->id, 'Desvincular'));
        $this->assertDatabaseCount('cash_movements', 2);
        $this->assertFalse($first->fresh()->hasStatementLinks());

        $other = $this->duplicatePendingLine($line->fresh(), 60);
        $line->update(['amount' => 40]);
        $whole = $this->createCashMovement($direction, 100);
        $service->reconcile($this->company->id, [$line->id, $other->id], 'cash_movement', [['id' => $whole->id]], $this->user->id);
        $this->assertSame(4000, (int) $line->fresh()->cashMovements->sole()->pivot->amount);
        $this->assertSame(6000, (int) $other->fresh()->cashMovements->sole()->pivot->amount);
        $this->assertNull($this->resolveService->reconcileWithCashMovement($this->duplicatePendingLine($line->fresh(), 100), $whole->id));
    }

    public function test_group_rejects_invalid_compositions_without_financial_effects(): void
    {
        $line = $this->importLine('CREDIT', '100.00', 'group-invalid');
        $movement = $this->createCashMovement(CashMovementDirection::INFLOW, 100);
        $wrongDirection = $this->createCashMovement(CashMovementDirection::OUTFLOW, 100);
        $otherAccount = $this->financialAccount->replicate();
        $otherAccount->name = 'Outra conta';
        $otherAccount->save();
        $wrongAccount = $this->createCashMovement(CashMovementDirection::INFLOW, 100, ['financial_account_id' => $otherAccount->id]);
        $foreign = $this->createCashMovement(CashMovementDirection::INFLOW, 100, ['company_id' => Company::factory()->create()->id]);
        $reversed = $this->createCashMovement(CashMovementDirection::INFLOW, 100, ['reversed_at' => now()]);
        $low = $this->createCashMovement(CashMovementDirection::INFLOW, 99.99);
        $installment = $this->createReceivableInstallmentForRollback();
        $cases = [
            ['cash_movement', [['id' => $movement->id], ['id' => $movement->id]]],
            ['cash_movement', [['id' => $wrongDirection->id]]],
            ['cash_movement', [['id' => $wrongAccount->id]]],
            ['cash_movement', [['id' => $foreign->id]]],
            ['cash_movement', [['id' => $reversed->id]]],
            ['cash_movement', [['id' => $low->id]]],
            ['receivable', [['id' => $installment->id, 'amount' => 101]]],
            ['receivable', [['id' => $installment->id, 'amount' => -100]]],
        ];
        foreach ($cases as [$type, $items]) {
            try {
                app(ReconcileBankStatementGroupService::class)->reconcile($this->company->id, [$line->id], $type, $items, $this->user->id);
                $this->fail('Composição inválida aceita.');
            } catch (ValidationException) {
                $this->assertSame('pending', $line->fresh()->reconciliation_status->value);
                $this->assertEquals(100, $installment->fresh()->balance_amount);
                $this->assertDatabaseCount('bank_reconciliation_groups', 0);
                $this->assertDatabaseCount('bank_statement_allocations', 0);
            }
        }
    }

    public function test_group_rolls_back_all_payments_when_a_later_installment_cannot_be_paid(): void
    {
        $first = $this->createPayableInstallmentForRollback();
        $second = $first->replicate();
        $second->sequence_number = '02';
        $second->status = AccountPayableStatus::CANCELLED;
        $second->save();
        $line = $this->importLine('DEBIT', '-100.00', 'group-rollback');
        try {
            app(ReconcileBankStatementGroupService::class)->reconcile($this->company->id, [$line->id], 'payable', [
                ['id' => $first->id, 'amount' => 50], ['id' => $second->id, 'amount' => 50],
            ], $this->user->id);
            $this->fail('Expected failed payment.');
        } catch (ValidationException) {
            $this->assertEquals(100, $first->fresh()->balance_amount);
            $this->assertDatabaseCount('account_payable_installment_payments', 0);
            $this->assertDatabaseCount('cash_movements', 0);
            $this->assertDatabaseCount('bank_statement_allocations', 0);
            $this->assertDatabaseCount('bank_reconciliation_groups', 0);
        }
    }

    private function duplicatePendingLine(BankStatementLine $line, float $amount): BankStatementLine
    {
        $other = $line->replicate();
        $other->fill(['amount' => $amount, 'cash_movement_id' => null, 'bank_reconciliation_group_id' => null, 'reconciliation_status' => 'pending', 'reconciled_at' => null, 'external_id' => (string) Str::uuid(), 'transaction_key' => (string) Str::uuid()]);
        $other->save();

        return $other;
    }

    public function test_multiple_reconciliation_actions_work_on_the_list_and_import_lines(): void
    {
        $this->user->companies()->attach($this->company, ['role' => 'member', 'is_active' => true]);
        $this->actingAs($this->user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->company);
        $this->user->companyAccesses()->create([
            'company_id' => $this->company->id, 'permissions' => ['cash_movements.access'],
            'panels' => ['admin' => true], 'navigation' => [],
        ]);
        $line = $this->importLine('CREDIT', '40.00', 'group-ui');
        $other = $this->duplicatePendingLine($line, 60);
        $movement = $this->createCashMovement(CashMovementDirection::INFLOW, 100);
        $workbench = Livewire::test(ListBankStatementLines::class)
            ->assertTableActionDoesNotExist('reconcile_movement')
            ->assertTableActionDoesNotExist('reconcile_payable_installment')
            ->assertTableActionDoesNotExist('reconcile_receivable_installment')
            ->mountTableBulkAction('reconcile_multiple', [$line, $other]);
        view()->share('errors', new ViewErrorBag);
        $modalHtml = $workbench->instance()->getSchemaComponent('mountedActionSchema0.selection')->toHtml();
        $this->assertStringContainsString('Já lançado no sistema', $modalHtml);
        $this->assertStringContainsString('Confirmar conciliação', $modalHtml);
        $results = $workbench->instance()->callSchemaComponentMethod('mountedActionSchema0.selection', 'searchRecords', ['type' => 'cash_movement']);
        $this->assertSame([$movement->id], array_column($results['records'], 'id'));
        $workbench->setTableBulkActionData([
            'selection' => ['type' => 'cash_movement', 'items' => [['id' => $movement->id, 'amount' => 100]]],
        ])->callMountedTableBulkAction()->assertHasNoTableBulkActionErrors();
        $this->assertSame('reconciled', $line->fresh()->reconciliation_status->value);
        $this->assertSame('reconciled', $other->fresh()->reconciliation_status->value);
        $this->resolveService->reverseReconciliation($line->fresh(), $this->user->id, 'Testar ação individual');
        $line->update(['amount' => 100]);
        Livewire::test(LinesRelationManager::class, [
            'ownerRecord' => $line->import,
            'pageClass' => ViewBankStatementImport::class,
        ])->assertTableActionDoesNotExist('reconcile_movement')
            ->assertTableActionDoesNotExist('reconcile_payable_installment')
            ->assertTableActionDoesNotExist('reconcile_receivable_installment')
            ->callTableAction('reconcile_multiple', $line, [
                'selection' => ['type' => 'cash_movement', 'items' => [['id' => $movement->id, 'amount' => 100]]],
            ])->assertHasNoTableActionErrors();
        $this->assertSame('reconciled', $line->fresh()->reconciliation_status->value);
    }

    public function test_group_survives_reimport_and_can_be_reversed_after_bank_changes(): void
    {
        $line = $this->importLine('CREDIT', '100.00', 'group-reimport');
        $movement = $this->createCashMovement(CashMovementDirection::INFLOW, 100);
        app(ReconcileBankStatementGroupService::class)->reconcile($this->company->id, [$line->id], 'cash_movement', [['id' => $movement->id]], $this->user->id);
        $groupId = $line->fresh()->bank_reconciliation_group_id;
        $this->importService->importFromString($this->company->id, $this->financialAccount->id, $this->buildOfx('237', [
            ['type' => 'CREDIT', 'date' => '20260411', 'amount' => '110.00', 'fitid' => 'group-reimport', 'memo' => 'Valor atualizado'],
        ]), 'updated.ofx', $this->user->id);
        $this->assertSame('needs_review', $line->fresh()->reconciliation_status->value);
        $this->assertSame($groupId, $line->fresh()->bank_reconciliation_group_id);
        $this->assertCount(1, $line->fresh()->cashMovements);
        $this->assertNull($this->resolveService->resolveReview($line->fresh(), $this->user->id, 'reopen', 'Reabrir sem desfazer'));
        $this->assertNotNull($this->resolveService->reverseReconciliation($line->fresh(), $this->user->id, 'Banco corrigiu valor'));
        $this->assertSame('pending', $line->fresh()->reconciliation_status->value);
        $this->assertDatabaseCount('cash_movements', 1);
        $this->assertDatabaseCount('bank_statement_allocations', 0);
    }

    #[DataProvider('reconciliationTypes')]
    public function test_workbench_searches_installments_by_party_description_id_and_brazilian_amount(string $type, string $bankType, string $amount): void
    {
        $installment = $type === 'receivable' ? $this->createReceivableInstallmentForRollback() : $this->createPayableInstallmentForRollback();
        $line = $this->importLine($bankType, $amount, 'workbench-search');
        $search = app(SearchBankReconciliationCandidates::class);
        foreach ([$type === 'receivable' ? 'Cliente OFX' : 'Fornecedor OFX', 'sujeito a rollback', '#'.$installment->id, 'R$ 100,00'] as $term) {
            $results = $search->search($this->company->id, collect([$line]), $type, $term);
            $this->assertSame([$installment->id], array_column($results['records'], 'id'), $term);
            $this->assertTrue($results['records'][0]['exact_match']);
        }
        $installment->update(['status' => 'cancelled']);
        $this->assertSame([], $search->search($this->company->id, collect([$line]), $type)['records']);
    }

    public function test_workbench_search_is_tenant_scoped_ranked_paginated_and_excludes_linked_movements(): void
    {
        $line = $this->importLine('CREDIT', '100.00', 'workbench-movements');
        $exact = $this->createCashMovement(CashMovementDirection::INFLOW, 100, ['counterparty_partner_id' => $this->customer->id]);
        $closest = $this->createCashMovement(CashMovementDirection::INFLOW, 99.99, ['transaction_date' => '2026-01-01']);
        $foreign = $this->createCashMovement(CashMovementDirection::INFLOW, 100, ['company_id' => Company::factory()->create()->id]);
        $opposite = $this->createCashMovement(CashMovementDirection::OUTFLOW, 100);
        $reversed = $this->createCashMovement(CashMovementDirection::INFLOW, 100, ['reversed_at' => now()]);
        for ($i = 0; $i < 20; $i++) {
            $this->createCashMovement(CashMovementDirection::INFLOW, 1);
        }
        $service = app(SearchBankReconciliationCandidates::class);
        $first = $service->search($this->company->id, collect([$line]), 'cash_movement');
        $this->assertCount(20, $first['records']);
        $this->assertTrue($first['has_more']);
        $this->assertSame($exact->id, $first['records'][0]['id']);
        $this->assertSame($closest->id, $first['records'][1]['id']);
        $this->assertTrue($first['records'][1]['date_exception']);
        foreach ([$foreign, $opposite, $reversed] as $excluded) {
            $this->assertNotContains($excluded->id, array_column($first['records'], 'id'));
        }
        $second = $service->search($this->company->id, collect([$line]), 'cash_movement', page: 2);
        $this->assertCount(2, $second['records']);
        $this->assertFalse($second['has_more']);
        $party = $service->search($this->company->id, collect([$line]), 'cash_movement', 'Cliente OFX');
        $this->assertSame([$exact->id], array_column($party['records'], 'id'));
        $this->resolveService->reconcileWithCashMovement($line, $exact->id, $this->user->id);
        $this->assertSame([], $service->search($this->company->id, collect([$line]), 'cash_movement', 'Cliente OFX')['records']);

        $this->expectException(ValidationException::class);
        $service->search($foreign->company_id, collect([$line]), 'cash_movement');
    }

    public function test_group_reversal_failure_restores_all_links_and_financial_effects(): void
    {
        $installment = $this->createPayableInstallmentForRollback();
        $line = $this->importLine('DEBIT', '-40.00', 'group-reversal-failure');
        $other = $this->duplicatePendingLine($line, 60);
        app(ReconcileBankStatementGroupService::class)->reconcile($this->company->id, [$line->id, $other->id], 'payable', [['id' => $installment->id, 'amount' => 100]], $this->user->id);
        // Reversal removes the latest payment first. Fail only after that could be undone.
        DB::table('account_payable_installment_payments')->where('id', $line->cashMovements->sole()->origin_id)->delete();
        $this->assertNull($this->resolveService->reverseReconciliation($line->fresh(), $this->user->id, 'Falha no segundo item'));
        $this->assertSame('reconciled', $line->fresh()->reconciliation_status->value);
        $this->assertSame('reconciled', $other->fresh()->reconciliation_status->value);
        $this->assertEquals(0, $installment->fresh()->balance_amount);
        $this->assertDatabaseCount('bank_statement_allocations', 2);
        $this->assertDatabaseCount('cash_movements', 2);
        $this->assertDatabaseCount('account_payable_installment_payments', 1);
    }

    public function test_group_rejects_foreign_mixed_and_already_resolved_bank_lines(): void
    {
        $line = $this->importLine('CREDIT', '100.00', 'group-line-validation');
        $movement = $this->createCashMovement(CashMovementDirection::INFLOW, 200);
        $other = $this->duplicatePendingLine($line, 100);
        $cases = [
            ['company_id' => Company::factory()->create()->id],
            ['metadata' => ['direction' => 'outflow']],
            ['reconciliation_status' => 'ignored'],
        ];
        foreach ($cases as $attributes) {
            $other->update(['company_id' => $line->company_id, 'metadata' => $line->metadata, 'reconciliation_status' => 'pending', ...$attributes]);
            try {
                app(ReconcileBankStatementGroupService::class)->reconcile($this->company->id, [$line->id, $other->id], 'cash_movement', [['id' => $movement->id]], $this->user->id);
                $this->fail('Expected incompatible bank lines.');
            } catch (ValidationException) {
                $this->assertFalse($movement->hasStatementLinks());
                $this->assertSame('pending', $line->fresh()->reconciliation_status->value);
                $this->assertDatabaseCount('bank_reconciliation_groups', 0);
            }
        }
    }

    public function test_group_checks_date_exception_and_is_included_in_operational_reset(): void
    {
        $line = $this->importLine('CREDIT', '100.00', 'group-date');
        $movement = $this->createCashMovement(CashMovementDirection::INFLOW, 100, ['transaction_date' => '2026-03-01']);
        $service = app(ReconcileBankStatementGroupService::class);
        try {
            $service->reconcile($this->company->id, [$line->id], 'cash_movement', [['id' => $movement->id]], $this->user->id);
            $this->fail('Expected date exception justification.');
        } catch (ValidationException) {
            $this->assertFalse($movement->hasStatementLinks());
        }
        $service->reconcile($this->company->id, [$line->id], 'cash_movement', [['id' => $movement->id]], $this->user->id, 'Data de lançamento ajustada pelo banco');
        $plan = app(CompanyOperationalResetService::class)->plan($this->company->id);
        $this->assertSame([], $plan['blockers']);
        $this->assertContains($line->fresh()->bank_reconciliation_group_id, $plan['delete']['bank_reconciliation_groups']);
        $this->assertCount(1, $plan['delete']['bank_statement_allocations']);
        $this->assertLessThan(array_search('bank_statement_lines', $plan['order']), array_search('bank_statement_allocations', $plan['order']));
    }

    #[DataProvider('reconciliationTypes')]
    public function test_group_settles_multiple_installments_with_individual_charges_discounts_and_notes(string $type, string $bankType): void
    {
        $first = $type === 'receivable' ? $this->createReceivableInstallmentForRollback() : $this->createPayableInstallmentForRollback();
        $second = $first->replicate();
        $second->sequence_number = '02';
        $second->save();
        $line = $this->importLine($bankType, $type === 'receivable' ? '202.00' : '-202.00', 'group-charges');
        app(ReconcileBankStatementGroupService::class)->reconcile($this->company->id, [$line->id], $type, [
            ['id' => $first->id, 'amount' => 112, 'interest_amount' => 10, 'fine_amount' => 5, 'discount_amount' => 3, 'notes' => 'Ajuste da primeira parcela'],
            ['id' => $second->id, 'amount' => 90, 'discount_amount' => 10],
        ], $this->user->id);

        $this->assertEquals(0, $first->fresh()->balance_amount);
        $this->assertEquals(0, $second->fresh()->balance_amount);
        $payment = $first->payments()->sole();
        $this->assertEquals(112, $payment->amount);
        $this->assertEquals(10, $payment->interest_amount);
        $this->assertEquals(5, $payment->fine_amount);
        $this->assertEquals(3, $payment->discount_amount);
        $this->assertSame('Ajuste da primeira parcela', $payment->notes);
        $this->assertEquals(10, $second->payments()->sole()->discount_amount);
        $this->assertEquals(20200, $line->cashMovements()->sum('bank_statement_allocations.amount'));
        $this->assertNotNull($this->resolveService->reverseReconciliation($line->fresh(), $this->user->id, 'Reverter ajustes'));
        $this->assertEquals(100, $first->fresh()->balance_amount);
        $this->assertEquals(100, $second->fresh()->balance_amount);
        $this->assertEquals(0, $first->fresh()->interest_amount);
        $this->assertEquals(0, $first->fresh()->fine_amount);
        $this->assertEquals(0, $first->fresh()->discount_amount);
        $this->assertDatabaseCount('bank_statement_allocations', 0);
    }

    #[DataProvider('reconciliationTypes')]
    public function test_group_applies_charges_once_across_bank_lines_and_reverses_payments_in_dependency_order(string $type, string $bankType): void
    {
        $installment = $type === 'receivable' ? $this->createReceivableInstallmentForRollback() : $this->createPayableInstallmentForRollback();
        $line = $this->importLine($bankType, $type === 'receivable' ? '150.00' : '-150.00', 'group-charges-split');
        $other = $this->duplicatePendingLine($line, 150.02);
        $other->update(['transaction_date' => '2026-04-12']);
        app(ReconcileBankStatementGroupService::class)->reconcile($this->company->id, [$line->id, $other->id], $type, [
            ['id' => $installment->id, 'amount' => 300.02, 'interest_amount' => 200, 'fine_amount' => 0.03, 'discount_amount' => 0.01],
        ], $this->user->id);

        $payments = $installment->payments()->orderBy('id')->get();
        $this->assertCount(2, $payments);
        $this->assertEquals(200, $payments[0]->interest_amount);
        $this->assertEquals(0.03, $payments[0]->fine_amount);
        $this->assertEquals(0.01, $payments[0]->discount_amount);
        $this->assertEquals(0, $payments[1]->interest_amount);
        $this->assertEquals(0, $payments[1]->fine_amount);
        $this->assertEquals(0, $payments[1]->discount_amount);
        $this->assertSame('2026-04-11', $payments[0]->payment_date->toDateString());
        $this->assertSame('2026-04-12', $payments[1]->payment_date->toDateString());
        $this->assertEquals(0, $installment->fresh()->balance_amount);
        $this->assertNotNull($this->resolveService->reverseReconciliation($other->fresh(), $this->user->id, 'Desfazer baixas com encargos'));
        $this->assertEquals(100, $installment->fresh()->balance_amount);
        $this->assertSame(0, $installment->payments()->count());
        $this->assertDatabaseCount('cash_movements', 0);
    }

    #[DataProvider('reconciliationTypes')]
    public function test_group_partial_payment_preserves_adjusted_balance(string $type, string $bankType): void
    {
        $installment = $type === 'receivable' ? $this->createReceivableInstallmentForRollback() : $this->createPayableInstallmentForRollback();
        $line = $this->importLine($bankType, $type === 'receivable' ? '60.00' : '-60.00', 'group-charges-partial');
        app(ReconcileBankStatementGroupService::class)->reconcile($this->company->id, [$line->id], $type, [
            ['id' => $installment->id, 'amount' => 60, 'interest_amount' => 20, 'fine_amount' => 5, 'discount_amount' => 10],
        ], $this->user->id);
        $this->assertEquals(115, $installment->fresh()->due_amount);
        $this->assertEquals(55, $installment->fresh()->balance_amount);
        $this->assertEquals(60, $line->fresh()->cashMovements->sole()->amount);
        $this->assertNotNull($this->resolveService->reverseReconciliation($line->fresh(), $this->user->id, 'Reabrir parcial'));
        $this->assertEquals(100, $installment->fresh()->balance_amount);
    }

    public function test_group_rejects_invalid_charges_without_changing_balances(): void
    {
        $installment = $this->createPayableInstallmentForRollback();
        $line = $this->importLine('DEBIT', '-100.00', 'group-invalid-charges');
        foreach ([
            ['interest_amount' => -1], ['fine_amount' => -0.001], ['discount_amount' => -1],
            ['interest_amount' => 'inválido'], ['fine_amount' => null], ['discount_amount' => 1],
            ['discount_amount' => 101],
        ] as $charges) {
            try {
                app(ReconcileBankStatementGroupService::class)->reconcile($this->company->id, [$line->id], 'payable', [
                    ['id' => $installment->id, 'amount' => 100, ...$charges],
                ], $this->user->id);
                $this->fail('Invalid charges were accepted.');
            } catch (ValidationException) {
                $this->assertEquals(100, $installment->fresh()->balance_amount);
                $this->assertDatabaseCount('account_payable_installment_payments', 0);
                $this->assertDatabaseCount('bank_reconciliation_groups', 0);
            }
        }
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

    public static function suggestionDateDistances(): array
    {
        return [
            'mesmo dia' => [0, 55, 75],
            'um dia antes' => [-1, 47, 67],
            'um dia depois' => [1, 47, 67],
            'vinte dias antes' => [-20, 35, 55],
            'vinte dias depois' => [20, 35, 55],
        ];
    }

    #[DataProvider('suggestionDateDistances')]
    public function test_suggestions_score_absolute_date_distances_and_award_the_same_day_bonus(int $days, int $installmentScore, int $movementScore): void
    {
        $this->financialAccount->update(['reconciliation_date_tolerance_days' => 30]);
        $service = app(SuggestBankStatementMatchesService::class);

        foreach (self::reconciliationTypes() as [$type, $bankType, $amount]) {
            $installment = $type === 'receivable' ? $this->createReceivableInstallmentForRollback() : $this->createPayableInstallmentForRollback();
            $line = $this->importLine($bankType, $amount, 'suggestion-dates-'.$type);
            $line->update(['description' => 'ABC']);
            $date = $line->transaction_date->copy()->addDays($days)->toDateString();
            $installment->update(['due_date' => $date]);
            $movement = $this->createCashMovement($line->direction(), 100, ['description' => 'XYZ', 'transaction_date' => $date]);
            $suggestions = collect($service->suggestForLine($line));

            $this->assertTrue($service->isSuccess(), $service->getMessageUser());
            $this->assertSame($installmentScore, $suggestions->firstWhere('origin_type', 'account_'.$type.'_installment')['score']);
            $this->assertSame($movementScore, $suggestions->firstWhere('origin_type', 'cash_movement')['score']);
            $this->assertSame($movement->id, $suggestions->firstWhere('origin_type', 'cash_movement')['origin_id']);
            $this->assertStringContainsString('diferenca de '.abs($days).' dia(s)', $suggestions->firstWhere('origin_type', 'cash_movement')['reason']);
        }
    }

    #[DataProvider('reconciliationTypes')]
    public function test_suggestions_rank_installments_beyond_the_first_thirty_and_across_query_batches(string $type, string $bankType, string $amount): void
    {
        $first = $type === 'receivable' ? $this->createReceivableInstallmentForRollback() : $this->createPayableInstallmentForRollback();
        $first->update(['original_amount' => 500, 'due_amount' => 500, 'balance_amount' => 500]);
        for ($i = 1; $i < 205; $i++) {
            $copy = $first->replicate();
            $copy->sequence_number = (string) ($i + 1);
            $copy->save();
        }
        for ($i = 0; $i < 6; $i++) {
            $near = $first->replicate();
            $near->fill(['sequence_number' => (string) (206 + $i), 'original_amount' => 100.5, 'due_amount' => 100.5, 'balance_amount' => 100.5]);
            $near->save();
        }
        $target = $first->replicate();
        $target->fill(['sequence_number' => '212', 'original_amount' => 100, 'due_amount' => 100, 'balance_amount' => 100]);
        $target->save();
        $line = $this->importLine($bankType, $amount, 'suggestion-batches');

        $this->assertCount(5, $line->suggestions());
        $this->assertSame($target->id, $line->suggestions()[0]['origin_id']);
        $this->assertGreaterThan($line->suggestions()[1]['score'], $line->suggestions()[0]['score']);
        $this->assertSame('ready', $line->suggestionsStatus());
    }

    #[DataProvider('reconciliationTypes')]
    public function test_suggestions_and_manual_search_share_installment_eligibility(string $type, string $bankType, string $amount): void
    {
        $eligible = $type === 'receivable' ? $this->createReceivableInstallmentForRollback() : $this->createPayableInstallmentForRollback();
        $foreignCompany = Company::factory()->create();
        foreach ([
            ['ignored_at' => now()],
            ['status' => 'cancelled'],
            ['balance_amount' => 0],
            ['status' => $type === 'receivable' ? 'received' : 'paid'],
            ['company_id' => $foreignCompany->id],
        ] as $attributes) {
            $copy = $eligible->replicate();
            $copy->save();
            // Include inconsistent legacy balances/statuses to verify eligibility, not model recalculation.
            if (array_key_exists('balance_amount', $attributes)) {
                $attributes['balance_amount'] *= 100;
            }
            DB::table($copy->getTable())->where('id', $copy->id)->update($attributes);
        }
        $parentRelation = $type === 'receivable' ? 'accountReceivable' : 'accountPayable';
        $parentKey = $type === 'receivable' ? 'account_receivable_id' : 'account_payable_id';
        foreach ([['status' => 'cancelled'], ['company_id' => $foreignCompany->id]] as $attributes) {
            $parent = $eligible->{$parentRelation}->replicate();
            $parent->save();
            DB::table($parent->getTable())->where('id', $parent->id)->update($attributes);
            $copy = $eligible->replicate();
            $copy->{$parentKey} = $parent->id;
            $copy->save();
        }
        $line = $this->importLine($bankType, $amount, 'suggestion-eligibility');
        $records = app(SearchBankReconciliationCandidates::class)->search($this->company->id, collect([$line]), $type)['records'];

        $this->assertSame([$eligible->id], array_column($line->suggestions(), 'origin_id'));
        $this->assertSame([$eligible->id], array_column($records, 'id'));
    }

    #[DataProvider('reconciliationTypes')]
    public function test_reconciliation_revalidates_ignored_installments_before_creating_payments(string $type, string $bankType, string $amount): void
    {
        $installment = $type === 'receivable' ? $this->createReceivableInstallmentForRollback() : $this->createPayableInstallmentForRollback();
        $line = $this->importLine($bankType, $amount, 'suggestion-revalidate');
        $this->assertNotEmpty($line->suggestions());
        DB::table($installment->getTable())->where('id', $installment->id)->update(['ignored_at' => now()]);
        $resolved = $type === 'receivable'
            ? $this->resolveService->reconcileWithReceivableInstallment($line, $installment->id)
            : $this->resolveService->reconcileWithPayableInstallment($line, $installment->id);

        $this->assertNull($resolved);
        $this->assertTrue($this->resolveService->hasError());
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertSame('pending', $line->fresh()->reconciliation_status->value);

        try {
            app(ReconcileBankStatementGroupService::class)->reconcile($this->company->id, [$line->id], $type, [['id' => $installment->id, 'amount' => 100]], $this->user->id);
            $this->fail('An ignored installment must not be settled by a group.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('cash_movements', 0);
            $this->assertDatabaseCount('bank_reconciliation_groups', 0);
            $this->assertEquals(100, $installment->fresh()->balance_amount);
        }
    }

    public function test_suggestions_exclude_movements_already_linked_to_the_current_line_or_a_group(): void
    {
        $line = $this->importLine('CREDIT', '100.00', 'suggestion-linked');
        $legacy = $this->createCashMovement(CashMovementDirection::INFLOW, 100);
        $grouped = $this->createCashMovement(CashMovementDirection::INFLOW, 100);
        $eligible = $this->createCashMovement(CashMovementDirection::INFLOW, 100);
        $line->update(['cash_movement_id' => $legacy->id]);
        $groupLine = $this->duplicatePendingLine($line, 100);
        app(ReconcileBankStatementGroupService::class)->reconcile($this->company->id, [$groupLine->id], 'cash_movement', [['id' => $grouped->id]], $this->user->id);

        $suggestions = app(SuggestBankStatementMatchesService::class)->suggestForLine($line->fresh());
        $records = app(SearchBankReconciliationCandidates::class)->search($this->company->id, collect([$line->fresh()]), 'cash_movement')['records'];

        $this->assertSame([$eligible->id], array_column($suggestions, 'origin_id'));
        $this->assertSame([$eligible->id], array_column($records, 'id'));
    }

    public function test_date_exceptions_are_required_for_movements_before_and_after_the_bank_date(): void
    {
        $line = $this->importLine('CREDIT', '100.00', 'suggestion-date-exceptions');
        foreach ([-10, 10] as $days) {
            $movement = $this->createCashMovement(CashMovementDirection::INFLOW, 100, [
                'transaction_date' => $line->transaction_date->copy()->addDays($days)->toDateString(),
            ]);
            $this->assertNull($this->resolveService->reconcileWithCashMovement($line, $movement->id));
            $this->assertArrayHasKey('exception_reason', $this->resolveService->getErrors());
        }
    }

    public function test_suggestion_states_distinguish_empty_stale_legacy_and_refreshed_results(): void
    {
        config(['bank_reconciliation.suggestions_ttl_minutes' => 60]);
        $line = $this->importLine('CREDIT', '100.00', 'suggestion-states');
        $this->assertSame('empty', $line->suggestionsStatus());
        $this->assertSame([], $line->suggestions());
        $metadata = $line->metadata;
        $line->metadata = array_diff_key($metadata, ['suggestions_version' => true]);
        $this->assertSame('stale', $line->suggestionsStatus());
        $line->metadata = array_merge($metadata, ['suggested_at' => 'not-a-date']);
        $this->assertSame('stale', $line->suggestionsStatus());
        $line->metadata = $metadata;

        $this->travel(60)->minutes();
        $this->assertSame('stale', $line->suggestionsStatus());
        $movement = $this->createCashMovement(CashMovementDirection::INFLOW, 100);
        $suggestions = $this->resolveService->refreshSuggestions($line);
        $this->assertTrue($this->resolveService->isSuccess());
        $this->assertSame($movement->id, $suggestions[0]['origin_id']);
        $this->assertSame('ready', $line->fresh()->suggestionsStatus());
    }

    public function test_refresh_suggestions_reports_failure_preserves_previous_results_and_recovers(): void
    {
        Log::spy();
        $movement = $this->createCashMovement(CashMovementDirection::INFLOW, 100);
        $line = $this->importLine('CREDIT', '100.00', 'suggestion-failure');
        $metadata = $line->metadata;
        $this->mock(BankStatementMovementEligibilityService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('queryForLine')->once()->andThrow(new \RuntimeException('Internal database detail'));
        });

        $this->assertSame([], $this->resolveService->refreshSuggestions($line));
        $this->assertTrue($this->resolveService->hasError());
        $this->assertStringNotContainsString('Internal database detail', $this->resolveService->getMessageUser());
        $line->refresh();
        $this->assertSame('error', $line->suggestionsStatus());
        $this->assertSame($metadata['suggestions'], $line->suggestions());
        $this->assertSame($metadata['suggested_at'], $line->metadata['suggested_at']);
        $this->assertSame($this->resolveService->getErrorCode(), $line->metadata['suggestions_error_code']);
        Log::shouldHaveReceived('error')->once();

        $this->app->forgetInstance(BankStatementMovementEligibilityService::class);
        $this->assertSame($movement->id, $this->resolveService->refreshSuggestions($line)[0]['origin_id']);
        $this->assertTrue($this->resolveService->isSuccess());
        $this->assertSame('ready', $line->fresh()->suggestionsStatus());
        $this->assertNull($line->fresh()->metadata['suggestions_error_code']);
    }

    public function test_import_records_suggestion_failure_instead_of_an_empty_successful_search(): void
    {
        Log::spy();
        $this->mock(BankStatementMovementEligibilityService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('queryForLine')->once()->andThrow(new \RuntimeException('Search unavailable'));
        });
        $line = $this->importLine('CREDIT', '100.00', 'suggestion-import-failure');

        $this->assertSame('error', $line->suggestionsStatus());
        $this->assertSame([], $line->suggestions());
        $this->assertNotEmpty($line->metadata['suggestions_error_code']);
        $this->assertArrayNotHasKey('suggested_at', $line->metadata);
        $this->assertSame('pending', $line->reconciliation_status->value);
        $this->assertTrue($this->importService->isSuccess());
        Log::shouldHaveReceived('error')->once();
    }

    public function test_refresh_suggestions_action_displays_failures_and_empty_results_without_false_success(): void
    {
        $this->user->companies()->attach($this->company, ['role' => 'member', 'is_active' => true]);
        $this->actingAs($this->user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->company);
        $this->user->companyAccesses()->create([
            'company_id' => $this->company->id, 'permissions' => ['cash_movements.access'],
            'panels' => ['admin' => true], 'navigation' => [],
        ]);
        $line = $this->importLine('CREDIT', '100.00', 'suggestion-action');
        $this->mock(BankStatementMovementEligibilityService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('queryForLine')->once()->andThrow(new \RuntimeException('Search unavailable'));
        });

        Livewire::test(LinesRelationManager::class, [
            'ownerRecord' => $line->import,
            'pageClass' => ViewBankStatementImport::class,
        ])->callTableAction('refresh_suggestions', $line)
            ->assertNotified(Notification::make()->title('Erro ao gerar sugestões de conciliação.')
                ->body('Não foi possível atualizar as sugestões. Tente novamente.')->danger());
        $this->assertSame('error', $line->fresh()->suggestionsStatus());

        $this->app->forgetInstance(BankStatementMovementEligibilityService::class);
        Livewire::test(LinesRelationManager::class, [
            'ownerRecord' => $line->import,
            'pageClass' => ViewBankStatementImport::class,
        ])->callTableAction('refresh_suggestions', $line)
            ->assertNotified(Notification::make()->title('Nenhuma correspondência encontrada para esta linha.')->success())
            ->assertTableActionHidden('use_single_suggestion', $line->fresh());
        $this->assertSame('empty', $line->fresh()->suggestionsStatus());
    }

    #[DataProvider('reconciliationTypes')]
    public function test_workbench_recommendations_discover_new_movements_and_installments_with_current_explanations(string $type, string $bankType, string $amount): void
    {
        $line = $this->importLine($bankType, $amount, 'recommendations-discovery');
        $this->assertSame('empty', $line->suggestionsStatus());
        $installment = $type === 'receivable' ? $this->createReceivableInstallmentForRollback() : $this->createPayableInstallmentForRollback();
        $party = $type === 'receivable' ? $this->customer : $this->supplier;
        $movement = $this->createCashMovement($line->direction(), 100, ['counterparty_partner_id' => $party->id]);
        $response = app(GetBankReconciliationSuggestions::class)->get($this->company->id, collect([$line]));
        $records = collect($response['records'])->keyBy('type');

        $this->assertSame(0, $response['failed_lines']);
        $this->assertCount(2, $records);
        $this->assertSame($movement->id, $records['cash_movement']['id']);
        $this->assertSame($installment->id, $records[$type]['id']);
        foreach ($records as $record) {
            $this->assertSame(10000, $record['amount_cents']);
            $this->assertTrue($record['exact_match']);
            $this->assertSame($party->name, $record['party']);
            $this->assertSame([$line->id], $record['statement_line_ids']);
            $this->assertStringContainsString('Mesmo valor', $record['reason']);
            $this->assertStringContainsString('Identificação: '.$party->name, $record['reason']);
        }
        $this->assertStringContainsString('na mesma data', $records['cash_movement']['reason']);
        $this->assertStringContainsString('1 dia(s) antes', $records[$type]['reason']);
        $this->assertSame('ready', $line->fresh()->suggestionsStatus());

        $movement->update(['reversed_at' => now()]);
        DB::table($installment->getTable())->where('id', $installment->id)->update(['ignored_at' => now()]);
        $response = app(GetBankReconciliationSuggestions::class)->get($this->company->id, collect([$line]));
        $this->assertSame([], $response['records']);
        $this->assertSame('empty', $line->fresh()->suggestionsStatus());
    }

    public function test_workbench_recommendations_refresh_expired_results_and_use_live_bank_line_data(): void
    {
        $line = $this->importLine('CREDIT', '100.00', 'recommendations-expired');
        $movement = $this->createCashMovement(CashMovementDirection::INFLOW, 200);
        DB::table('bank_statement_lines')->where('id', $line->id)->update(['amount' => 20000]);
        $this->travel(25)->hours();
        $this->assertSame('stale', $line->suggestionsStatus());

        $response = app(GetBankReconciliationSuggestions::class)->get($this->company->id, collect([$line]));

        $this->assertSame($movement->id, $response['records'][0]['id']);
        $this->assertSame(20000, $response['records'][0]['amount_cents']);
        $this->assertTrue($response['records'][0]['exact_match']);
        $this->assertSame('ready', $line->fresh()->suggestionsStatus());
    }

    public function test_bulk_recommendations_deduplicate_targets_and_compare_values_to_the_selection_total(): void
    {
        $line = $this->importLine('CREDIT', '100.00', 'recommendations-bulk');
        $other = $this->duplicatePendingLine($line, 100);
        $movement = $this->createCashMovement(CashMovementDirection::INFLOW, 100);

        $response = app(GetBankReconciliationSuggestions::class)->get($this->company->id, collect([$line, $other]));

        $this->assertCount(1, $response['records']);
        $this->assertSame($movement->id, $response['records'][0]['id']);
        $this->assertSame([$line->id, $other->id], $response['records'][0]['statement_line_ids']);
        $this->assertFalse($response['records'][0]['exact_match']);
        $this->assertSame(0, $response['failed_lines']);
    }

    public function test_recommendations_reject_foreign_mixed_and_no_longer_pending_selections(): void
    {
        $line = $this->importLine('CREDIT', '100.00', 'recommendations-invalid');
        $foreignCompany = Company::factory()->create();
        $service = app(GetBankReconciliationSuggestions::class);
        $other = $this->duplicatePendingLine($line, 100);
        foreach ([
            ['company_id' => $foreignCompany->id],
            ['metadata' => ['direction' => 'outflow']],
            ['reconciliation_status' => 'ignored'],
        ] as $attributes) {
            $other->update(['company_id' => $line->company_id, 'metadata' => $line->metadata, 'reconciliation_status' => 'pending', ...$attributes]);
            try {
                $service->get($this->company->id, collect([$line, $other]));
                $this->fail('Expected an invalid selection to be rejected.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('selection', $exception->errors());
            }
        }
        // The passed model is pending, but the persisted record is not.
        DB::table('bank_statement_lines')->where('id', $line->id)->update(['reconciliation_status' => 'reconciled']);
        $this->expectException(ValidationException::class);
        $service->get($this->company->id, collect([$line]));
    }

    public function test_recommendation_failures_are_reported_without_disabling_manual_search(): void
    {
        Log::spy();
        $line = $this->importLine('CREDIT', '100.00', 'recommendations-failure');
        $movement = $this->createCashMovement(CashMovementDirection::INFLOW, 100);
        $this->mock(BankStatementMovementEligibilityService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('queryForLine')->once()->andThrow(new \RuntimeException('Search unavailable'));
        });

        $response = app(GetBankReconciliationSuggestions::class)->get($this->company->id, collect([$line]));
        $this->assertSame([], $response['records']);
        $this->assertSame(1, $response['failed_lines']);
        $this->assertSame('error', $line->fresh()->suggestionsStatus());
        $manual = app(SearchBankReconciliationCandidates::class)->search($this->company->id, collect([$line]), 'cash_movement');
        $this->assertSame($movement->id, $manual['records'][0]['id']);
    }

    public function test_bulk_recommendations_preserve_successful_results_when_one_line_fails(): void
    {
        Log::spy();
        $line = $this->importLine('CREDIT', '100.00', 'recommendations-partial-failure');
        $other = $this->duplicatePendingLine($line, 100);
        $movement = $this->createCashMovement(CashMovementDirection::INFLOW, 100);
        $eligibility = new BankStatementMovementEligibilityService;
        $this->mock(BankStatementMovementEligibilityService::class, function (MockInterface $mock) use ($line, $eligibility): void {
            $mock->shouldReceive('queryForLine')->twice()->andReturnUsing(function (BankStatementLine $candidateLine) use ($line, $eligibility) {
                if ($candidateLine->id === $line->id) {
                    throw new \RuntimeException('Search unavailable for one line');
                }

                return $eligibility->queryForLine($candidateLine);
            });
        });

        $response = app(GetBankReconciliationSuggestions::class)->get($this->company->id, collect([$line, $other]));

        $this->assertSame(1, $response['failed_lines']);
        $this->assertSame($movement->id, $response['records'][0]['id']);
        $this->assertSame([$other->id], $response['records'][0]['statement_line_ids']);
        $this->assertSame('error', $line->fresh()->suggestionsStatus());
        $this->assertSame('ready', $other->fresh()->suggestionsStatus());
    }

    #[DataProvider('reconciliationTypes')]
    public function test_both_statement_screens_expose_recommendations_and_revalidate_selection_on_confirmation(string $type, string $bankType, string $amount): void
    {
        $this->user->companies()->attach($this->company, ['role' => 'member', 'is_active' => true]);
        $this->actingAs($this->user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->company);
        $this->user->companyAccesses()->create([
            'company_id' => $this->company->id, 'permissions' => ['cash_movements.access'],
            'panels' => ['admin' => true], 'navigation' => [],
        ]);
        $line = $this->importLine($bankType, $amount, 'recommendations-ui');
        $installment = $type === 'receivable' ? $this->createReceivableInstallmentForRollback() : $this->createPayableInstallmentForRollback();

        foreach ([ListBankStatementLines::class, LinesRelationManager::class] as $screen) {
            DB::table($installment->getTable())->where('id', $installment->id)->update(['ignored_at' => null]);
            $options = $screen === LinesRelationManager::class ? ['ownerRecord' => $line->import, 'pageClass' => ViewBankStatementImport::class] : [];
            $component = Livewire::test($screen, $options)
                ->assertTableColumnExists('suggestions_count')
                ->assertTableColumnExists('metadata.suggestions.0.label')
                ->assertTableActionExists('refresh_suggestions')
                ->mountTableAction('reconcile_multiple', $line);
            view()->share('errors', new ViewErrorBag);
            $html = $component->instance()->getSchemaComponent('mountedActionSchema0.selection')->toHtml();
            $this->assertStringContainsString('Sugestões recomendadas', $html);
            $response = $component->instance()->callSchemaComponentMethod('mountedActionSchema0.selection', 'loadSuggestions', []);
            $this->assertSame($type, $response['records'][0]['type']);
            $this->assertSame($installment->id, $response['records'][0]['id']);
            DB::table($installment->getTable())->where('id', $installment->id)->update(['ignored_at' => now()]);

            $component->setTableActionData([
                'selection' => ['type' => $type, 'items' => [['id' => $installment->id, 'amount' => 100]]],
            ])->callMountedTableAction()
                ->assertNotified('Confira a seleção antes de conciliar');
            $this->assertSame('pending', $line->fresh()->reconciliation_status->value);
            $this->assertDatabaseCount('cash_movements', 0);
        }
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

        return $import?->lines()->where('external_id', $fitid)->sole() ?? throw new \RuntimeException('Não foi possível importar a linha de teste.');
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
