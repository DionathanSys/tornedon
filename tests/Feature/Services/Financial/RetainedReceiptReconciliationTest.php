<?php

namespace Tests\Feature\Services\Financial;

use App\Enum\Financial\ChartAccountType;
use App\Enum\Financial\DreMode;
use App\Enum\Financial\DreView;
use App\Filament\Clusters\Financial\Resources\BankStatementLines\Pages\ListBankStatementLines;
use App\Models\AccountPayable;
use App\Models\AccountReceivable;
use App\Models\AccountReceivableInstallment;
use App\Models\BankReconciliationGroup;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Models\CashMovement;
use App\Models\ChartAccount;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\Invoice;
use App\Models\Partner;
use App\Models\ResultCenter;
use App\Models\User;
use App\Services\AccountPayable\AccountPayableService;
use App\Services\Financial\BankStatement\ReceiptWithRetainedExpensesReversalService;
use App\Services\Financial\BankStatement\ReconcileReceiptWithRetainedExpensesService;
use App\Services\Financial\BankStatement\ReconcileRetainedReceiptReturnService;
use App\Services\Financial\BankStatement\ResolveBankStatementLineService;
use App\Services\Financial\CashMovementService;
use App\Services\Financial\Dre\GenerateDreReportService;
use App\Services\Financial\Dre\SetupDreService;
use App\Services\Financial\FinancialClassificationService;
use App\Services\Invoice\InvoiceFinancialLinkService;
use App\Services\Invoice\InvoiceReceiptReversalService;
use App\Support\Financial\Money;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class RetainedReceiptReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private FinancialAccount $bank;

    private FinancialCategory $revenue;

    private FinancialCategory $expense;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->company = Company::factory()->create(['created_by' => $this->user->id]);
        $this->user->companies()->syncWithoutDetaching([$this->company->id => ['role' => 'owner', 'is_active' => true]]);
        $this->actingAs($this->user);
        $this->bank = FinancialAccount::create(['company_id' => $this->company->id, 'name' => 'Banco', 'type' => 'bank', 'opening_balance' => '0.00', 'is_active' => true]);
        $this->revenue = $this->category('1', ChartAccountType::REVENUE);
        $this->expense = $this->category('2', ChartAccountType::EXPENSE);
    }

    public function test_settles_gross_receipt_and_classified_payable_against_one_net_deposit(): void
    {
        $title = $this->installment();
        $line = $this->line();
        $group = $this->reconcile($line, $title);
        $this->assertSame('confirmed', $group->status);
        $this->assertSame(0, Money::stored($title->fresh(), 'balance_amount'));
        $this->assertSame(460000, Money::stored($title->fresh(), 'received_amount'));
        $fee = AccountPayable::sole();
        $this->assertTrue($fee->paid);
        $this->assertSame($this->expense->id, $fee->installments()->sole()->financial_category_id);
        $this->assertSame(10000, Money::stored($fee, 'paid_amount'));
        $this->assertSame(4500.0, $this->bank->fresh()->current_balance);
        $this->assertDatabaseCount('cash_movements', 2);
        $this->assertDatabaseCount('bank_statement_allocations', 2);
        $this->assertSame(460000, Money::stored($line->cashMovements()->where('direction', 'inflow')->sole()));
        $this->assertSame(10000, Money::stored($line->cashMovements()->where('direction', 'outflow')->sole()));
        $this->assertSame(2, $group->items()->count());
        $this->artisan('financial:audit-integrity', ['--company' => $this->company->id])->assertSuccessful();
    }

    public function test_partial_receipt_and_multiple_expenses_preserve_balance_and_categories(): void
    {
        $other = $this->category('3', ChartAccountType::EXPENSE);
        $title = $this->installment();
        $group = $this->reconcile($this->line('3000.00'), $title, ['amount' => '3100.00', 'expenses' => [$this->fee('80.00'), $this->fee('20.00', ['financial_category_id' => $other->id])]]);
        $this->assertSame(150000, Money::stored($title->fresh(), 'balance_amount'));
        $this->assertSame(3, $group->items()->count());
        $this->assertSame([$this->expense->id, $other->id], AccountPayable::all()->map(fn ($document) => $document->installments()->sole()->financial_category_id)->all());
        $this->assertSame(3000.0, $this->bank->fresh()->current_balance);
    }

    public function test_dre_preserves_discount_separation_without_counting_fee_twice(): void
    {
        $title = $this->installment('1000.00');
        $setup = app(SetupDreService::class);
        $model = $setup->create($this->company->id, ['name' => 'Guiada', 'accounts' => $setup->suggestions($this->company->id)]);
        $this->reconcile($this->line('930.00'), $title, ['amount' => '950.00', 'discount_amount' => '50.00', 'expenses' => [$this->fee('20.00')]]);
        foreach (DreMode::cases() as $mode) {
            $report = app(GenerateDreReportService::class)->generate($model, [$this->company->id], '2026-11-01', '2026-11-30', $mode, DreView::REALIZED);
            $this->assertEquals(1000, $report->lines->firstWhere('code', 'RECEITAS')->amount);
            $this->assertEquals(-50, $report->lines->firstWhere('code', 'DESCONTOS_RECEBIMENTO')->amount);
            $this->assertEquals(930, $report->lines->firstWhere('code', 'RESULTADO')->amount);
            $projected = app(GenerateDreReportService::class)->generate($model, [$this->company->id], '2026-11-01', '2026-11-30', $mode, DreView::PROJECTED_ONLY);
            $this->assertEquals(0, $projected->lines->firstWhere('code', 'RESULTADO')->amount);
        }
    }

    public function test_idempotent_retry_does_not_duplicate_and_changed_payload_is_rejected(): void
    {
        $line = $this->line();
        $title = $this->installment();
        $key = (string) Str::uuid();
        $group = $this->reconcile($line, $title, key: $key);
        $this->assertSame($group->id, $this->reconcile($line, $title, key: $key)->id);
        $this->assertDatabaseCount('cash_movements', 2);
        $this->expectException(ValidationException::class);
        $this->reconcile($line, $title, ['notes' => 'Outra composição'], $key);
    }

    public function test_another_request_cannot_reconcile_an_already_used_bank_line(): void
    {
        $line = $this->line();
        $title = $this->installment();
        $this->reconcile($line, $title);
        $this->expectException(ValidationException::class);
        $this->reconcile($line, $title);
    }

    public function test_divergence_and_excess_precision_have_no_financial_effect(): void
    {
        $title = $this->installment();
        $line = $this->line('4500.01');
        try {
            $this->reconcile($line, $title);
            $this->fail('Deve rejeitar um centavo de divergência.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('cash_movements', 0);
            $this->assertDatabaseCount('bank_reconciliation_groups', 0);
        }
        $this->expectException(ValidationException::class);
        $this->reconcile($line, $title, ['amount' => '4600.001']);
    }

    public function test_failure_creating_a_later_expense_rolls_back_receipt_and_previous_expenses(): void
    {
        $title = $this->installment();
        try {
            $this->reconcile($this->line(), $title, ['expenses' => [$this->fee('80.00'), $this->fee('20.00', ['financial_category_id' => 999999])]]);
            $this->fail('Deve falhar na segunda categoria.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('account_payables', 0);
            $this->assertDatabaseCount('cash_movements', 0);
            $this->assertDatabaseCount('account_receivable_installment_payments', 0);
            $this->assertDatabaseCount('bank_reconciliation_groups', 0);
            $this->assertSame(460000, Money::stored($title->fresh(), 'balance_amount'));
        }
    }

    public function test_correction_preserves_history_restores_balance_and_allows_new_reconciliation(): void
    {
        $title = $this->installment();
        $line = $this->line();
        $group = $this->reconcile($line, $title);
        $resolved = app(ResolveBankStatementLineService::class)->reverseReconciliation($line->fresh(), $this->user->id, 'Recebimento escolhido incorretamente.');
        $this->assertNotNull($resolved);
        $this->assertSame('pending', $resolved->reconciliation_status->value);
        $this->assertSame('cancelled', $group->fresh()->status);
        $this->assertSame(460000, Money::stored($title->fresh(), 'balance_amount'));
        $this->assertSame(0.0, $this->bank->fresh()->current_balance);
        $this->assertSame('cancelled', AccountPayable::sole()->status->value);
        $this->assertDatabaseCount('cash_movements', 2);
        $this->assertDatabaseCount('account_receivable_installment_payments', 1);
        $this->assertSame(0, CashMovement::effective()->count());
        $this->assertDatabaseCount('bank_statement_allocations', 0);
        $this->artisan('financial:audit-integrity', ['--company' => $this->company->id])->assertSuccessful();
        $new = $this->reconcile($line->fresh(), $title->fresh());
        $this->assertNotSame($group->id, $new->id);
        $this->assertSame(4500.0, $this->bank->fresh()->current_balance);
    }

    public function test_cancelled_operation_does_not_contribute_to_realized_dre_or_discounts(): void
    {
        $setup = app(SetupDreService::class);
        $model = $setup->create($this->company->id, ['name' => 'Guiada', 'accounts' => $setup->suggestions($this->company->id)]);
        $group = $this->reconcile($this->line('930.00'), $this->installment('1000.00'), ['amount' => '950.00', 'discount_amount' => '50.00', 'expenses' => [$this->fee('20.00')]]);
        app(ReceiptWithRetainedExpensesReversalService::class)->correct($this->company->id, $group->id, $this->user->id, 'Correção de vínculo indevido');
        foreach (DreMode::cases() as $mode) {
            $report = app(GenerateDreReportService::class)->generate($model, [$this->company->id], '2026-11-01', '2026-11-30', $mode, DreView::REALIZED);
            $this->assertEquals(0, $report->lines->firstWhere('code', 'RESULTADO')->amount);
        }
    }

    public function test_real_return_is_posted_in_its_own_month_without_assuming_fee_refund(): void
    {
        $setup = app(SetupDreService::class);
        $model = $setup->create($this->company->id, ['name' => 'Guiada', 'accounts' => $setup->suggestions($this->company->id)]);
        $group = $this->reconcile($this->line(), $this->installment());
        app(ReceiptWithRetainedExpensesReversalService::class)->returnFunds($this->company->id, $group->id, $this->user->id, '2026-12-02', 'Devolução confirmada pelo financeiro');
        $this->assertSame(-100.0, $this->bank->fresh()->current_balance);
        $this->assertTrue(AccountPayable::sole()->paid);
        $this->assertSame(1, CashMovement::whereDate('transaction_date', '2026-12-02')->whereNotNull('reversal_of_id')->count());
        foreach ([['2026-11-01', '2026-11-30', 4500], ['2026-12-01', '2026-12-31', -4600]] as [$start, $end, $expected]) {
            $report = app(GenerateDreReportService::class)->generate($model, [$this->company->id], $start, $end, DreMode::CASH, DreView::REALIZED);
            $this->assertEquals($expected, $report->lines->firstWhere('code', 'RESULTADO')->amount);
        }
    }

    public function test_explicit_fee_refund_reverses_both_components_on_requested_date(): void
    {
        $group = $this->reconcile($this->line(), $this->installment());
        $expenseId = $group->items()->where('role', 'retained_expense')->sole()->id;
        app(ReceiptWithRetainedExpensesReversalService::class)->returnFunds($this->company->id, $group->id, $this->user->id, '2026-12-02', 'Devolução e ressarcimento da taxa', [$expenseId]);
        $this->assertSame(0.0, $this->bank->fresh()->current_balance);
        $this->assertSame('cancelled', AccountPayable::sole()->status->value);
        $this->assertSame(2, CashMovement::whereDate('transaction_date', '2026-12-02')->count());
        $this->artisan('financial:audit-integrity', ['--company' => $this->company->id])->assertSuccessful();
    }

    public function test_components_cannot_be_edited_individually(): void
    {
        $group = $this->reconcile($this->line(), $this->installment());
        $this->expectException(ValidationException::class);
        $group->items()->where('role', 'retained_expense')->sole()->payablePayment->update(['amount' => '90.00']);
    }

    public function test_other_company_titles_and_categories_are_rejected(): void
    {
        $other = Company::factory()->create();
        $category = FinancialCategory::create(['company_id' => $other->id, 'name' => 'Outra', 'is_active' => true, 'allow_payable' => true]);
        $this->expectException(ValidationException::class);
        $this->reconcile($this->line(), $this->installment(), ['expenses' => [$this->fee('100.00', ['financial_category_id' => $category->id])]]);
    }

    public function test_each_bank_line_must_close_individually(): void
    {
        $first = $this->line('4500.00');
        $second = $this->line('4500.00');
        $one = $this->installment();
        $two = $this->installment();
        $group = app(ReconcileReceiptWithRetainedExpensesService::class)->reconcile($this->company->id, [$first->id, $second->id], [
            ['id' => $one->id, 'statement_line_id' => $first->id, 'amount' => '4600.00', 'expenses' => [$this->fee()]],
            ['id' => $two->id, 'statement_line_id' => $second->id, 'amount' => '4600.00', 'expenses' => [$this->fee()]],
        ], (string) Str::uuid(), $this->user->id);
        $this->assertSame(4, $group->items()->count());
        $this->assertSame(9000.0, $this->bank->fresh()->current_balance);
    }

    public function test_filament_workbench_confirms_receipt_with_expenses(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->company);
        $line = $this->line();
        $title = $this->installment();
        Livewire::test(ListBankStatementLines::class)
            ->callTableAction('reconcile_multiple', $line, ['selection' => [
                'type' => 'retained_receipt', 'idempotency_key' => (string) Str::uuid(),
                'items' => [['id' => $title->id, 'gross_cents' => 460000, 'expenses' => [$this->fee()]]],
            ]])->assertHasNoTableActionErrors();
        $this->assertSame('reconciled', $line->fresh()->reconciliation_status->value);
        $this->assertSame(4500.0, $this->bank->fresh()->current_balance);
    }

    public function test_expense_payment_failure_rolls_back_all_effects(): void
    {
        $service = \Mockery::mock(AccountPayableService::class, [new FinancialClassificationService, new CashMovementService])->makePartial();
        $service->shouldReceive('registerInstallmentPayment')->once()->andReturnNull();
        app()->instance(AccountPayableService::class, $service);
        $title = $this->installment();
        $line = $this->line();
        try {
            $this->reconcile($line, $title);
            $this->fail('A falha na baixa da despesa deve interromper a operação.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('account_payables', 0);
            $this->assertDatabaseCount('cash_movements', 0);
            $this->assertDatabaseCount('bank_reconciliation_group_items', 0);
            $this->assertSame('pending', $line->fresh()->reconciliation_status->value);
            $this->assertSame(460000, Money::stored($title->fresh(), 'balance_amount'));
        }
    }

    public function test_failed_correction_restores_links_and_financial_effects(): void
    {
        $line = $this->line();
        $group = $this->reconcile($line, $this->installment());
        $service = \Mockery::mock(AccountPayableService::class, [new FinancialClassificationService, new CashMovementService])->makePartial();
        $service->shouldReceive('cancel')->once()->andReturnFalse();
        app()->instance(AccountPayableService::class, $service);
        try {
            app(ReceiptWithRetainedExpensesReversalService::class)->correct($this->company->id, $group->id, $this->user->id, 'Correção com falha na despesa');
            $this->fail('A correção deve ser atômica.');
        } catch (ValidationException) {
            $this->assertSame('confirmed', $group->fresh()->status);
            $this->assertSame('reconciled', $line->fresh()->reconciliation_status->value);
            $this->assertSame(4500.0, $this->bank->fresh()->current_balance);
            $this->assertDatabaseCount('bank_statement_allocations', 2);
        }
    }

    public function test_cost_and_result_centers_are_inherited_and_dre_filters_remain_consistent(): void
    {
        $cost = CostCenter::create(['company_id' => $this->company->id, 'name' => 'Administrativo', 'is_active' => true]);
        $result = ResultCenter::create(['company_id' => $this->company->id, 'name' => 'Unidade A', 'is_active' => true]);
        $title = $this->installment('4600.29');
        $title->update(['cost_center_id' => $cost->id, 'result_center_id' => $result->id]);
        $this->reconcile($this->line('4500.01'), $title, ['amount' => '4600.29', 'expenses' => [$this->fee('100.28')]]);
        $fee = AccountPayable::sole()->installments()->sole();
        $this->assertSame($cost->id, $fee->cost_center_id);
        $this->assertSame($result->id, $fee->result_center_id);
        $this->assertSame(4500.01, $this->bank->fresh()->current_balance);
        $setup = app(SetupDreService::class);
        $model = $setup->create($this->company->id, ['name' => 'Guiada', 'accounts' => $setup->suggestions($this->company->id)]);
        foreach (DreMode::cases() as $mode) {
            $report = app(GenerateDreReportService::class)->generate($model, [$this->company->id], '2026-11-01', '2026-11-30', $mode, DreView::REALIZED, $cost->id, $result->id);
            $this->assertEquals(4500.01, $report->lines->firstWhere('code', 'RESULTADO')->amount);
        }
    }

    public function test_correction_preserves_invoice_receipt_history(): void
    {
        $partner = Partner::create(['name' => 'Cliente da fatura', 'document_type' => 'CPF', 'document_number' => '12345678901', 'created_by' => $this->user->id]);
        $invoice = Invoice::create(['company_id' => $this->company->id, 'customer_id' => $partner->id, 'invoice_number' => 'RET-1', 'invoice_date' => '2026-11-01', 'status' => 'confirmed', 'confirmed' => true, 'pending' => false, 'created_by' => $this->user->id]);
        $title = $this->installment();
        $title->accountReceivable->update(['invoice_id' => $invoice->id]);
        app(InvoiceFinancialLinkService::class)->recordGenerated($title->accountReceivable->fresh(), $this->user->id);
        $link = $title->accountReceivable->invoiceLinks()->sole();
        $group = $this->reconcile($this->line(), $title);
        app(ReceiptWithRetainedExpensesReversalService::class)->correct($this->company->id, $group->id, $this->user->id, 'Correção preservando o vínculo da fatura');
        $this->assertDatabaseCount('account_receivable_installment_payments', 1);
        $this->assertSame($title->account_receivable_id, $link->fresh()->account_receivable_id);
        $this->assertSame(460000, Money::stored($title->fresh(), 'balance_amount'));
        $this->assertSame([], app(InvoiceReceiptReversalService::class)->blockers($title->accountReceivable->fresh()));
    }

    public function test_return_can_be_conciliated_by_net_and_unlinked_without_reversing_it_again(): void
    {
        $group = $this->reconcile($this->line(), $this->installment());
        $expenseId = $group->items()->where('role', 'retained_expense')->sole()->id;
        app(ReceiptWithRetainedExpensesReversalService::class)->returnFunds($this->company->id, $group->id, $this->user->id, '2026-12-02', 'Devolução e ressarcimento confirmados', [$expenseId]);
        $line = $this->line();
        $line->update(['metadata' => ['direction' => 'outflow'], 'transaction_date' => '2026-12-02']);
        $service = app(ReconcileRetainedReceiptReturnService::class);
        $returnGroup = $service->reconcile($this->company->id, $line->id, $group->id, $this->user->id);
        $this->assertSame('reconciled', $line->fresh()->reconciliation_status->value);
        $this->assertSame(2, $returnGroup->items()->count());
        $service->unlink($line->fresh(), $this->user->id, 'Vínculo escolhido incorretamente');
        $this->assertSame('pending', $line->fresh()->reconciliation_status->value);
        $this->assertSame(0.0, $this->bank->fresh()->current_balance);
        $this->assertSame(2, $returnGroup->items()->count());
        $service->reconcile($this->company->id, $line->id, $group->id, $this->user->id);
        $this->assertDatabaseCount('cash_movements', 4);
    }

    private function reconcile(BankStatementLine $line, AccountReceivableInstallment $title, array $overrides = [], ?string $key = null): BankReconciliationGroup
    {
        return app(ReconcileReceiptWithRetainedExpensesService::class)->reconcile($this->company->id, [$line->id], [[
            'id' => $title->id, 'amount' => '4600.00', 'expenses' => [$this->fee()], ...$overrides,
        ]], $key ?? (string) Str::uuid(), $this->user->id);
    }

    private function fee(string $amount = '100.00', array $extra = []): array
    {
        return ['amount' => $amount, 'financial_category_id' => $this->expense->id, 'manual_counterparty_name' => 'Intermediador', ...$extra];
    }

    private function category(string $code, ChartAccountType $type): FinancialCategory
    {
        $chart = ChartAccount::create(['company_id' => $this->company->id, 'code' => $code, 'name' => $code, 'type' => $type, 'is_active' => true, 'is_postable' => true]);

        return FinancialCategory::create(['company_id' => $this->company->id, 'name' => 'Categoria '.$code, 'chart_account_id' => $chart->id, 'is_active' => true, 'allow_receivable' => $type === ChartAccountType::REVENUE, 'allow_payable' => $type === ChartAccountType::EXPENSE]);
    }

    private function installment(string $amount = '4600.00'): AccountReceivableInstallment
    {
        $document = AccountReceivable::create(['company_id' => $this->company->id, 'manual_counterparty_name' => 'Cliente', 'status' => 'pending', 'due_date' => '2026-11-10', 'due_amount' => $amount, 'paid' => false, 'paid_amount' => 0]);

        return $document->installments()->create(['company_id' => $this->company->id, 'sequence_number' => '01', 'status' => 'pending', 'due_date' => '2026-11-10', 'competence_date' => '2026-11-01', 'original_amount' => $amount, 'due_amount' => $amount, 'received_amount' => 0, 'balance_amount' => $amount, 'financial_category_id' => $this->revenue->id, 'chart_account_id' => $this->revenue->chart_account_id]);
    }

    private function line(string $amount = '4500.00'): BankStatementLine
    {
        $import = BankStatementImport::create(['company_id' => $this->company->id, 'financial_account_id' => $this->bank->id, 'status' => 'completed', 'file_name' => 'extrato.ofx']);

        return $import->lines()->create(['company_id' => $this->company->id, 'financial_account_id' => $this->bank->id, 'transaction_date' => '2026-11-15', 'amount' => $amount, 'description' => 'Depósito líquido', 'transaction_key' => (string) Str::uuid(), 'metadata' => ['direction' => 'inflow'], 'reconciliation_status' => 'pending']);
    }
}
