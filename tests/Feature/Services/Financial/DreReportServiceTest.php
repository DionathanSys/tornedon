<?php

namespace Tests\Feature\Services\Financial;

use App\Enum\AccountPayable\Status as PayableStatus;
use App\Enum\AccountReceivable\Status as ReceivableStatus;
use App\Enum\Financial\CashMovementDirection;
use App\Enum\Financial\ChartAccountType;
use App\Enum\Financial\DreLineType;
use App\Enum\Financial\DreMode;
use App\Enum\Financial\DreOperation;
use App\Enum\Financial\DreView;
use App\Enum\Financial\FinancialAccountType;
use App\Filament\Pages\DreReportPage;
use App\Models\AccountPayable;
use App\Models\AccountPayableInstallment;
use App\Models\AccountReceivable;
use App\Models\AccountReceivableInstallment;
use App\Models\AccountReceivableInstallmentPayment;
use App\Models\CashMovement;
use App\Models\ChartAccount;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\DreLine;
use App\Models\DreModel;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\ResultCenter;
use App\Models\User;
use App\Services\Financial\CashMovementService;
use App\Services\Financial\Dre\GenerateDreReportService;
use App\Services\Financial\Dre\SetupDreService;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class DreReportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_models_with_same_template_and_structure_are_equivalent(): void
    {
        $first = $this->createCompany('Empresa DRE A');
        $second = $this->createCompany('Empresa DRE B');

        $firstModel = $this->createModelWithLines($first->id, 'dre_padrao');
        $secondModel = $this->createModelWithLines($second->id, 'dre_padrao');

        $this->assertTrue($firstModel->fresh()->isStructurallyEquivalentTo($secondModel->fresh()));

        $secondModel->lines()->where('code', 'CUSTOS')->first()->update(['operation' => DreOperation::ADD->value]);

        $this->assertFalse($firstModel->fresh()->isStructurallyEquivalentTo($secondModel->fresh()));
    }

    public function test_competence_report_calculates_receivables_payables_and_manual_movements(): void
    {
        $company = $this->createCompany('Empresa DRE');
        $financialAccount = FinancialAccount::query()->create([
            'company_id' => $company->id,
            'name' => 'Banco',
            'type' => FinancialAccountType::BANK->value,
            'opening_balance' => 0,
            'is_active' => true,
        ]);
        $revenueAccount = $this->createChartAccount($company->id, '1', 'Receitas', ChartAccountType::REVENUE);
        $expenseAccount = $this->createChartAccount($company->id, '2', 'Despesas', ChartAccountType::EXPENSE);
        $revenueCategory = $this->createCategory($company->id, $revenueAccount->id, 'Receita', true, false);
        $expenseCategory = $this->createCategory($company->id, $expenseAccount->id, 'Despesa', false, true);
        $model = DreModel::query()->create([
            'company_id' => $company->id,
            'name' => 'DRE Gerencial',
            'template_key' => 'dre_gerencial',
            'is_active' => true,
        ]);
        $revenueLine = DreLine::query()->create([
            'dre_model_id' => $model->id,
            'code' => 'RECEITA',
            'name' => 'Receita Bruta',
            'line_type' => DreLineType::ACCOUNT_GROUP->value,
            'operation' => DreOperation::ADD->value,
            'sort_order' => 1,
        ]);
        $expenseLine = DreLine::query()->create([
            'dre_model_id' => $model->id,
            'code' => 'DESPESAS',
            'name' => 'Despesas',
            'line_type' => DreLineType::ACCOUNT_GROUP->value,
            'operation' => DreOperation::SUBTRACT->value,
            'sort_order' => 2,
        ]);
        $revenueLine->chartAccounts()->attach($revenueAccount->id, ['include_descendants' => true]);
        $expenseLine->chartAccounts()->attach($expenseAccount->id, ['include_descendants' => true]);

        $receivable = AccountReceivable::query()->create([
            'company_id' => $company->id,
            'manual_counterparty_name' => 'Cliente',
            'status' => ReceivableStatus::PENDING->value,
            'due_date' => '2026-08-10',
            'due_amount' => 1000,
            'paid_amount' => 0,
            'paid' => false,
        ]);
        AccountReceivableInstallment::query()->create([
            'account_receivable_id' => $receivable->id,
            'company_id' => $company->id,
            'sequence_number' => '01',
            'status' => ReceivableStatus::PENDING->value,
            'due_date' => '2026-08-10',
            'competence_date' => null,
            'original_amount' => 1000,
            'due_amount' => 1000,
            'received_amount' => 0,
            'balance_amount' => 1000,
            'financial_category_id' => $revenueCategory->id,
            'chart_account_id' => $revenueAccount->id,
        ]);
        $payable = AccountPayable::query()->create([
            'company_id' => $company->id,
            'manual_counterparty_name' => 'Fornecedor',
            'sequence_number' => '01',
            'status' => PayableStatus::PENDING->value,
            'due_date' => '2026-08-12',
            'due_amount' => 300,
            'paid_amount' => 0,
            'paid' => false,
        ]);
        AccountPayableInstallment::query()->create([
            'account_payable_id' => $payable->id,
            'company_id' => $company->id,
            'sequence_number' => '01',
            'status' => PayableStatus::PENDING->value,
            'due_date' => '2026-08-12',
            'competence_date' => null,
            'original_amount' => 300,
            'due_amount' => 300,
            'paid_amount' => 0,
            'balance_amount' => 300,
            'financial_category_id' => $expenseCategory->id,
            'chart_account_id' => $expenseAccount->id,
        ]);
        CashMovement::query()->create([
            'company_id' => $company->id,
            'financial_account_id' => $financialAccount->id,
            'financial_category_id' => $expenseCategory->id,
            'chart_account_id' => $expenseAccount->id,
            'direction' => CashMovementDirection::OUTFLOW->value,
            'transaction_date' => '2026-08-13',
            'competence_date' => null,
            'amount' => 50,
            'description' => 'Despesa manual',
            'origin_type' => 'manual',
        ]);

        $report = app(GenerateDreReportService::class)->generate(
            dreModel: $model->fresh(),
            companyIds: [$company->id],
            startDate: '2026-08-01',
            endDate: '2026-08-31',
            mode: DreMode::COMPETENCE,
            view: DreView::PROJECTED_AND_REALIZED,
        );

        $this->assertSame(1000.0, $report->lines->firstWhere('code', 'RECEITA')->amount);
        $this->assertSame(350.0, $report->lines->firstWhere('code', 'DESPESAS')->amount);
    }

    public function test_report_can_filter_by_cost_center_and_result_center(): void
    {
        $company = $this->createCompany('Empresa Centros');
        $revenueAccount = $this->createChartAccount($company->id, '1', 'Receitas', ChartAccountType::REVENUE);
        $revenueCategory = $this->createCategory($company->id, $revenueAccount->id, 'Receita', true, false);
        $costCenter = CostCenter::query()->create([
            'company_id' => $company->id,
            'code' => 'CC1',
            'name' => 'Transporte',
            'is_active' => true,
        ]);
        $otherCostCenter = CostCenter::query()->create([
            'company_id' => $company->id,
            'code' => 'CC2',
            'name' => 'Oficina',
            'is_active' => true,
        ]);
        $resultCenter = ResultCenter::query()->create([
            'company_id' => $company->id,
            'code' => 'CR1',
            'name' => 'Resultado Transporte',
            'is_active' => true,
        ]);
        $otherResultCenter = ResultCenter::query()->create([
            'company_id' => $company->id,
            'code' => 'CR2',
            'name' => 'Resultado Oficina',
            'is_active' => true,
        ]);
        $model = DreModel::query()->create([
            'company_id' => $company->id,
            'name' => 'DRE Gerencial',
            'template_key' => 'dre_gerencial',
            'is_active' => true,
        ]);
        $line = DreLine::query()->create([
            'dre_model_id' => $model->id,
            'code' => 'RECEITA',
            'name' => 'Receita Bruta',
            'line_type' => DreLineType::ACCOUNT_GROUP->value,
            'operation' => DreOperation::ADD->value,
            'sort_order' => 1,
        ]);
        $line->chartAccounts()->attach($revenueAccount->id, ['include_descendants' => true]);

        $receivable = AccountReceivable::query()->create([
            'company_id' => $company->id,
            'manual_counterparty_name' => 'Cliente',
            'status' => ReceivableStatus::PENDING->value,
            'due_date' => '2026-08-10',
            'due_amount' => 3000,
            'paid_amount' => 0,
            'paid' => false,
        ]);

        foreach ([[$costCenter->id, $resultCenter->id, 1000], [$otherCostCenter->id, $otherResultCenter->id, 2000]] as [$costCenterId, $resultCenterId, $amount]) {
            AccountReceivableInstallment::query()->create([
                'account_receivable_id' => $receivable->id,
                'company_id' => $company->id,
                'sequence_number' => (string) $amount,
                'status' => ReceivableStatus::PENDING->value,
                'due_date' => '2026-08-10',
                'competence_date' => '2026-08-10',
                'original_amount' => $amount,
                'due_amount' => $amount,
                'received_amount' => 0,
                'balance_amount' => $amount,
                'financial_category_id' => $revenueCategory->id,
                'chart_account_id' => $revenueAccount->id,
                'cost_center_id' => $costCenterId,
                'result_center_id' => $resultCenterId,
            ]);
        }

        $report = app(GenerateDreReportService::class)->generate(
            dreModel: $model->fresh(),
            companyIds: [$company->id],
            startDate: '2026-08-01',
            endDate: '2026-08-31',
            mode: DreMode::COMPETENCE,
            view: DreView::PROJECTED_AND_REALIZED,
            costCenterId: $costCenter->id,
            resultCenterId: $resultCenter->id,
        );

        $this->assertSame(1000.0, $report->lines->firstWhere('code', 'RECEITA')->amount);
    }

    public function test_reports_can_be_generated_for_distinct_comparison_periods(): void
    {
        $company = $this->createCompany('Empresa Comparativo');
        $revenueAccount = $this->createChartAccount($company->id, '1', 'Receitas', ChartAccountType::REVENUE);
        $revenueCategory = $this->createCategory($company->id, $revenueAccount->id, 'Receita', true, false);
        $model = DreModel::query()->create([
            'company_id' => $company->id,
            'name' => 'DRE Comparativo',
            'is_active' => true,
        ]);
        $line = DreLine::query()->create([
            'dre_model_id' => $model->id,
            'code' => 'RECEITA',
            'name' => 'Receita Bruta',
            'line_type' => DreLineType::ACCOUNT_GROUP->value,
            'operation' => DreOperation::ADD->value,
        ]);
        $line->chartAccounts()->attach($revenueAccount->id, ['include_descendants' => true]);
        $receivable = AccountReceivable::query()->create([
            'company_id' => $company->id,
            'manual_counterparty_name' => 'Cliente',
            'status' => ReceivableStatus::PENDING->value,
            'due_date' => '2026-07-10',
            'due_amount' => 1500,
            'paid_amount' => 0,
            'paid' => false,
        ]);

        foreach ([['2026-07-10', 1000], ['2026-08-10', 1500]] as [$dueDate, $amount]) {
            AccountReceivableInstallment::query()->create([
                'account_receivable_id' => $receivable->id,
                'company_id' => $company->id,
                'sequence_number' => (string) $amount,
                'status' => ReceivableStatus::PENDING->value,
                'due_date' => $dueDate,
                'competence_date' => $dueDate,
                'original_amount' => $amount,
                'due_amount' => $amount,
                'received_amount' => 0,
                'balance_amount' => $amount,
                'financial_category_id' => $revenueCategory->id,
                'chart_account_id' => $revenueAccount->id,
            ]);
        }

        $service = app(GenerateDreReportService::class);
        $previous = $service->generate($model, [$company->id], '2026-07-01', '2026-07-31');
        $current = $service->generate($model, [$company->id], '2026-08-01', '2026-08-31');

        $this->assertSame(1000.0, $previous->lines->firstWhere('code', 'RECEITA')->amount);
        $this->assertSame(1500.0, $current->lines->firstWhere('code', 'RECEITA')->amount);
        $this->assertSame(1500.0, $current->revenueBase);
    }

    public function test_report_separates_projected_and_realized_amounts(): void
    {
        $company = $this->createCompany('Empresa Previsto Realizado');
        $revenueAccount = $this->createChartAccount($company->id, '1', 'Receitas', ChartAccountType::REVENUE);
        $revenueCategory = $this->createCategory($company->id, $revenueAccount->id, 'Receita', true, false);
        $model = DreModel::query()->create([
            'company_id' => $company->id,
            'name' => 'DRE Previsto Realizado',
            'is_active' => true,
        ]);
        $line = DreLine::query()->create([
            'dre_model_id' => $model->id,
            'code' => 'RECEITA',
            'name' => 'Receita',
            'line_type' => DreLineType::ACCOUNT_GROUP->value,
            'operation' => DreOperation::ADD->value,
        ]);
        $line->chartAccounts()->attach($revenueAccount->id, ['include_descendants' => true]);
        $receivable = AccountReceivable::query()->create([
            'company_id' => $company->id,
            'manual_counterparty_name' => 'Cliente',
            'status' => ReceivableStatus::PENDING->value,
            'due_date' => '2026-08-10',
            'due_amount' => 900,
            'paid_amount' => 400,
            'paid' => false,
        ]);

        foreach ([[ReceivableStatus::PENDING, 500, 0, 500], [ReceivableStatus::RECEIVED, 400, 400, 0]] as [$status, $dueAmount, $receivedAmount, $balanceAmount]) {
            AccountReceivableInstallment::query()->create([
                'account_receivable_id' => $receivable->id,
                'company_id' => $company->id,
                'sequence_number' => (string) $dueAmount,
                'status' => $status->value,
                'due_date' => '2026-08-10',
                'competence_date' => '2026-08-10',
                'original_amount' => $dueAmount,
                'due_amount' => $dueAmount,
                'received_amount' => $receivedAmount,
                'balance_amount' => $balanceAmount,
                'financial_category_id' => $revenueCategory->id,
                'chart_account_id' => $revenueAccount->id,
            ]);
        }

        $service = app(GenerateDreReportService::class);
        $projected = $service->generate($model, [$company->id], '2026-08-01', '2026-08-31', view: DreView::PROJECTED_ONLY);
        $realized = $service->generate($model, [$company->id], '2026-08-01', '2026-08-31', view: DreView::REALIZED);
        $combined = $service->generate($model, [$company->id], '2026-08-01', '2026-08-31', view: DreView::PROJECTED_AND_REALIZED);

        $this->assertSame(500.0, $projected->lines->firstWhere('code', 'RECEITA')->amount);
        $this->assertSame(400.0, $realized->lines->firstWhere('code', 'RECEITA')->amount);
        $this->assertSame(900.0, $combined->lines->firstWhere('code', 'RECEITA')->amount);
    }

    public function test_header_sums_its_child_lines(): void
    {
        $company = $this->createCompany('Empresa Cabecalho');
        $revenueAccount = $this->createChartAccount($company->id, '1', 'Receitas', ChartAccountType::REVENUE);
        $revenueCategory = $this->createCategory($company->id, $revenueAccount->id, 'Receita', true, false);
        $model = DreModel::query()->create([
            'company_id' => $company->id,
            'name' => 'DRE Cabecalho',
            'is_active' => true,
        ]);
        $header = DreLine::query()->create([
            'dre_model_id' => $model->id,
            'code' => 'RECEITAS',
            'name' => 'Receitas',
            'line_type' => DreLineType::HEADER->value,
            'operation' => DreOperation::NONE->value,
        ]);
        $line = DreLine::query()->create([
            'dre_model_id' => $model->id,
            'parent_id' => $header->id,
            'code' => 'SERVICOS',
            'name' => 'Serviços',
            'line_type' => DreLineType::ACCOUNT_GROUP->value,
            'operation' => DreOperation::ADD->value,
        ]);
        $line->chartAccounts()->attach($revenueAccount->id, ['include_descendants' => true]);
        $receivable = AccountReceivable::query()->create([
            'company_id' => $company->id,
            'manual_counterparty_name' => 'Cliente',
            'status' => ReceivableStatus::PENDING->value,
            'due_date' => '2026-08-10',
            'due_amount' => 700,
            'paid_amount' => 0,
            'paid' => false,
        ]);
        AccountReceivableInstallment::query()->create([
            'account_receivable_id' => $receivable->id,
            'company_id' => $company->id,
            'sequence_number' => '01',
            'status' => ReceivableStatus::PENDING->value,
            'due_date' => '2026-08-10',
            'competence_date' => '2026-08-10',
            'original_amount' => 700,
            'due_amount' => 700,
            'received_amount' => 0,
            'balance_amount' => 700,
            'financial_category_id' => $revenueCategory->id,
            'chart_account_id' => $revenueAccount->id,
        ]);

        $report = app(GenerateDreReportService::class)->generate($model, [$company->id], '2026-08-01', '2026-08-31');

        $this->assertSame(700.0, $report->lines->firstWhere('code', 'RECEITAS')->amount);
    }

    public function test_cash_report_includes_payment_reversals_on_their_own_dates(): void
    {
        $company = $this->createCompany('Empresa Estornos');
        $account = $this->createChartAccount($company->id, '1', 'Receitas', ChartAccountType::REVENUE);
        $category = $this->createCategory($company->id, $account->id, 'Receitas', true, false);
        $bank = FinancialAccount::create([
            'company_id' => $company->id, 'name' => 'Banco', 'type' => FinancialAccountType::BANK,
            'opening_balance' => 0, 'is_active' => true,
        ]);
        $model = $this->createModelWithLines($company->id, 'estornos');
        $model->lines()->where('code', 'RECEITA')->first()->chartAccounts()->attach($account->id);
        $original = CashMovement::create([
            'company_id' => $company->id, 'financial_account_id' => $bank->id,
            'financial_category_id' => $category->id, 'chart_account_id' => $account->id,
            'direction' => CashMovementDirection::INFLOW, 'transaction_date' => '2026-08-15',
            'amount' => 100, 'description' => 'Recebimento',
            'origin_type' => AccountReceivableInstallmentPayment::class, 'origin_id' => 999,
        ]);
        $this->travelTo(Carbon::parse('2026-09-10'));
        $payment = new AccountReceivableInstallmentPayment;
        $payment->id = 999;
        $reversal = app(CashMovementService::class)->reverseForReceivablePayment($payment);
        $this->assertNotNull($reversal);
        $this->assertSame($original->id, $reversal->reversal_of_id);
        $service = app(GenerateDreReportService::class);
        $august = $service->generate($model, [$company->id], '2026-08-01', '2026-08-31', mode: DreMode::CASH, view: DreView::REALIZED);
        $september = $service->generate($model, [$company->id], '2026-09-01', '2026-09-30', mode: DreMode::CASH, view: DreView::REALIZED);
        $combined = $service->generate($model, [$company->id], '2026-08-01', '2026-09-30', mode: DreMode::CASH, view: DreView::REALIZED);
        $this->assertSame(100.0, $august->lines->firstWhere('code', 'RECEITA')->amount);
        $this->assertSame(-100.0, $september->lines->firstWhere('code', 'RECEITA')->amount);
        $this->assertSame(0.0, $combined->lines->firstWhere('code', 'RECEITA')->amount);
        $this->travelBack();
    }

    public function test_guided_dre_separates_discount_and_preserves_net_result_with_natural_expense_signs(): void
    {
        [$company, $model, $installment, $payment, $bank, $category] = $this->guidedReceipt(950, 50);
        $expense = $this->createChartAccount($company->id, '2', 'Despesas', ChartAccountType::EXPENSE);
        $expenseCategory = $this->createCategory($company->id, $expense->id, 'Despesas', false, true);
        $model->lines()->where('code', 'DESPESAS')->first()->chartAccounts()->attach($expense->id, ['include_descendants' => false]);
        CashMovement::create([
            'company_id' => $company->id, 'financial_account_id' => $bank->id,
            'financial_category_id' => $expenseCategory->id, 'chart_account_id' => $expense->id,
            'direction' => CashMovementDirection::OUTFLOW, 'transaction_date' => '2026-08-15',
            'amount' => 300, 'description' => 'Despesa', 'origin_type' => 'manual',
        ]);
        $service = app(GenerateDreReportService::class);
        foreach (DreMode::cases() as $mode) {
            $report = $service->generate($model, [$company->id], '2026-08-01', '2026-08-31', $mode, DreView::REALIZED);
            $this->assertSame(1000.0, $report->lines->firstWhere('code', 'RECEITAS')->amount);
            $this->assertSame(-50.0, $report->lines->firstWhere('code', 'DESCONTOS_RECEBIMENTO')->amount);
            $this->assertSame(-300.0, $report->lines->firstWhere('code', 'DESPESAS')->amount);
            $this->assertSame(700.0, $report->lines->firstWhere('code', 'RESULTADO_OPERACIONAL')->amount);
            $this->assertSame(650.0, $report->lines->firstWhere('code', 'RESULTADO')->amount);
        }
        $this->assertSame(2, CashMovement::where('company_id', $company->id)->count());
        $this->assertSame(950.0, (float) $payment->amount);
    }

    public function test_partial_receipt_keeps_projected_and_realized_discount_from_being_counted_twice(): void
    {
        [$company, $model] = $this->guidedReceipt(400, 50);
        $service = app(GenerateDreReportService::class);
        foreach (DreMode::cases() as $mode) {
            foreach ([DreView::REALIZED, DreView::PROJECTED_ONLY, DreView::PROJECTED_AND_REALIZED] as $view) {
                $report = $service->generate($model, [$company->id], '2026-08-01', '2026-08-31', $mode, $view);
                $expected = match ($view) {
                    DreView::REALIZED => [450.0, -50.0, 400.0],
                    DreView::PROJECTED_ONLY => [550.0, 0.0, 550.0],
                    default => [1000.0, -50.0, 950.0],
                };
                $this->assertEquals($expected[0], $report->lines->firstWhere('code', 'RECEITAS')->amount);
                $this->assertEquals($expected[1], $report->lines->firstWhere('code', 'DESCONTOS_RECEBIMENTO')->amount);
                $this->assertEquals($expected[2], $report->lines->firstWhere('code', 'RESULTADO')->amount);
            }
        }
    }

    public function test_discount_cash_reversal_is_present_in_its_own_period_and_competence_removes_returned_discount(): void
    {
        [$company, $model, $installment, $payment] = $this->guidedReceipt(950, 50);
        $this->travelTo(Carbon::parse('2026-09-10'));
        $this->assertNotNull(app(CashMovementService::class)->reverseForReceivablePayment($payment));
        $payment->update(['returned_amount' => 950]);
        $installment->update(['received_amount' => 0, 'discount_amount' => 0, 'due_amount' => 1000, 'balance_amount' => 1000, 'status' => ReceivableStatus::PENDING]);
        $service = app(GenerateDreReportService::class);
        foreach ([['2026-08-01', '2026-08-31', 1000.0, -50.0, 950.0], ['2026-09-01', '2026-09-30', -1000.0, 50.0, -950.0], ['2026-08-01', '2026-09-30', 0.0, 0.0, 0.0]] as [$start, $end, $gross, $discount, $net]) {
            $report = $service->generate($model, [$company->id], $start, $end, DreMode::CASH, DreView::REALIZED);
            $this->assertEquals($gross, $report->lines->firstWhere('code', 'RECEITAS')->amount);
            $this->assertEquals($discount, $report->lines->firstWhere('code', 'DESCONTOS_RECEBIMENTO')->amount);
            $this->assertEquals($net, $report->lines->firstWhere('code', 'RESULTADO')->amount);
        }
        $report = $service->generate($model, [$company->id], '2026-08-01', '2026-08-31');
        $this->assertEquals(0, $report->lines->firstWhere('code', 'DESCONTOS_RECEBIMENTO')->amount);
        $this->assertSame(1000.0, $report->lines->firstWhere('code', 'RESULTADO')->amount);
        $this->travelBack();
    }

    public function test_guided_mapping_rejects_foreign_and_duplicate_accounts_atomically(): void
    {
        $company = $this->createCompany('Empresa origem');
        $other = $this->createCompany('Outra empresa');
        $account = $this->createChartAccount($company->id, '1', 'Receita', ChartAccountType::REVENUE);
        $foreign = $this->createChartAccount($other->id, '1', 'Receita externa', ChartAccountType::REVENUE);
        foreach ([[$account->id, $foreign->id], [$account->id, $account->id]] as $ids) {
            try {
                app(SetupDreService::class)->create($company->id, [
                    'name' => 'Inválido', 'accounts' => array_map(fn ($id) => ['account_id' => $id, 'group' => 'revenue'], $ids),
                ]);
                $this->fail('Mapeamento inválido deve ser rejeitado.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('accounts', $e->errors());
            }
        }
        $this->assertSame(0, DreModel::count());
    }

    public function test_guided_mapping_does_not_double_count_parent_and_child_accounts(): void
    {
        [$company, $model, $installment] = $this->guidedReceipt(1000, 0);
        $child = $this->createChartAccount($company->id, '1.1', 'Serviços', ChartAccountType::REVENUE);
        $child->update(['parent_id' => $installment->chart_account_id]);
        $installment->update(['chart_account_id' => $child->id]);
        $setup = app(SetupDreService::class);
        $model = $setup->create($company->id, ['name' => 'Com filhas', 'accounts' => $setup->suggestions($company->id)]);
        $report = app(GenerateDreReportService::class)->generate($model, [$company->id], '2026-08-01', '2026-08-31');
        $this->assertSame(1000.0, $report->lines->firstWhere('code', 'RESULTADO')->amount);
        $this->assertEquals(0, $report->lines->firstWhere('code', 'DESCONTOS_RECEBIMENTO')->amount);
        $this->assertEquals(0, $model->lines()->where('code', 'RECEITAS')->first()->chartAccounts()->wherePivot('include_descendants', true)->count());
    }

    public function test_receipt_discount_respects_company_and_cost_and_result_center_filters(): void
    {
        [$company, $model, $installment, $payment] = $this->guidedReceipt(950, 50);
        $this->guidedReceipt(900, 100); // Another company must not contribute to this report.
        $costCenter = CostCenter::create(['company_id' => $company->id, 'name' => 'Oficina', 'is_active' => true]);
        $otherCostCenter = CostCenter::create(['company_id' => $company->id, 'name' => 'Comercial', 'is_active' => true]);
        $resultCenter = ResultCenter::create(['company_id' => $company->id, 'name' => 'Serviços', 'is_active' => true]);
        $installment->update(['cost_center_id' => $costCenter->id, 'result_center_id' => $resultCenter->id]);
        CashMovement::where('origin_type', $payment::class)->where('origin_id', $payment->id)
            ->update(['cost_center_id' => $costCenter->id, 'result_center_id' => $resultCenter->id]);
        $service = app(GenerateDreReportService::class);
        foreach (DreMode::cases() as $mode) {
            $report = $service->generate($model, [$company->id], '2026-08-01', '2026-08-31', $mode, DreView::REALIZED, $costCenter->id, $resultCenter->id);
            $this->assertSame(-50.0, $report->lines->firstWhere('code', 'DESCONTOS_RECEBIMENTO')->amount);
            $this->assertSame(950.0, $report->lines->firstWhere('code', 'RESULTADO')->amount);
            $empty = $service->generate($model, [$company->id], '2026-08-01', '2026-08-31', $mode, DreView::REALIZED, $otherCostCenter->id, $resultCenter->id);
            $this->assertEquals(0, $empty->lines->firstWhere('code', 'RESULTADO')->amount);
        }
    }

    public function test_guided_dre_refuses_overlapping_account_groups(): void
    {
        [$company, $model, $installment] = $this->guidedReceipt(950, 50);
        $model->lines()->where('code', 'OUTROS')->first()->chartAccounts()->attach($installment->chart_account_id);
        $this->expectException(ValidationException::class);
        app(GenerateDreReportService::class)->generate($model, [$company->id], '2026-08-01', '2026-08-31');
    }

    public function test_report_guided_action_creates_model_with_suggested_accounts_and_selects_it(): void
    {
        $company = $this->createCompany('Montagem pela tela');
        $user = User::findOrFail($company->created_by);
        $user->companies()->attach($company, ['role' => 'admin', 'is_active' => true]);
        $account = $this->createChartAccount($company->id, '1', 'Serviços', ChartAccountType::REVENUE);
        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
        Filament::setTenant($company);

        $component = Livewire::test(DreReportPage::class)
            ->assertSee('Comece pela montagem da sua DRE')
            ->assertActionVisible('setupDre')
            ->mountAction('setupDre')
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $model = DreModel::forCompany($company->id)->sole();
        $component->assertSet('data.dre_model_id', $model->id);
        $this->assertTrue($model->is_default);
        $this->assertSame([$account->id], $model->lines()->where('code', 'RECEITAS')->first()->chartAccounts()->pluck('chart_accounts.id')->all());
        $this->createChartAccount($company->id, '2', 'Conta nova sem vínculo', ChartAccountType::EXPENSE);
        $component->call('$refresh')->assertSee('Conta nova sem vínculo');
    }

    public function test_guided_action_shows_validation_when_chart_of_accounts_is_empty(): void
    {
        $company = $this->createCompany('Sem plano de contas');
        $user = User::findOrFail($company->created_by);
        $user->companies()->attach($company, ['role' => 'admin', 'is_active' => true]);
        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
        Filament::setTenant($company);

        Livewire::test(DreReportPage::class)
            ->mountAction('setupDre')
            ->callMountedAction()
            ->assertHasActionErrors(['accounts'])
            ->assertNotified(Notification::make()
                ->warning()
                ->title('Não foi possível criar a DRE')
                ->body('Classifique todas as contas e inclua ao menos uma na DRE. Cadastre o plano de contas caso esteja vazio.'));

        $this->assertSame(0, DreModel::count());
    }

    public function test_guided_action_shows_validation_when_accounts_change_while_modal_is_open(): void
    {
        $company = $this->createCompany('Plano alterado');
        $user = User::findOrFail($company->created_by);
        $user->companies()->attach($company, ['role' => 'admin', 'is_active' => true]);
        $this->createChartAccount($company->id, '1', 'Receitas', ChartAccountType::REVENUE);
        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
        Filament::setTenant($company);

        $component = Livewire::test(DreReportPage::class)->mountAction('setupDre');
        $this->createChartAccount($company->id, '2', 'Despesas', ChartAccountType::EXPENSE);

        $component->callMountedAction()
            ->assertHasActionErrors(['accounts'])
            ->assertNotified(Notification::make()
                ->warning()
                ->title('Não foi possível criar a DRE')
                ->body('Classifique todas as contas e inclua ao menos uma na DRE. Cadastre o plano de contas caso esteja vazio.'));

        $this->assertSame(0, DreModel::count());
    }

    private function guidedReceipt(float $received, float $discount): array
    {
        $company = $this->createCompany('Empresa guiada '.str()->uuid());
        $account = $this->createChartAccount($company->id, '1', 'Receitas', ChartAccountType::REVENUE);
        $category = $this->createCategory($company->id, $account->id, 'Receita', true, false);
        $bank = FinancialAccount::create([
            'company_id' => $company->id, 'name' => 'Banco', 'type' => FinancialAccountType::BANK,
            'opening_balance' => 0, 'is_active' => true,
        ]);
        $setup = app(SetupDreService::class);
        $model = $setup->create($company->id, ['name' => 'Guiada', 'accounts' => $setup->suggestions($company->id)]);
        $receivable = AccountReceivable::create([
            'company_id' => $company->id, 'manual_counterparty_name' => 'Cliente',
            'status' => ReceivableStatus::PENDING,
            'due_date' => '2026-08-10', 'due_amount' => 1000, 'paid_amount' => 0, 'paid' => false,
        ]);
        $installment = AccountReceivableInstallment::create([
            'account_receivable_id' => $receivable->id, 'company_id' => $company->id,
            'sequence_number' => '01', 'due_date' => '2026-08-10', 'competence_date' => '2026-08-01',
            'status' => $received + $discount >= 1000 ? ReceivableStatus::RECEIVED : ReceivableStatus::PARTIALLY_RECEIVED,
            'original_amount' => 1000, 'due_amount' => 1000 - $discount,
            'received_amount' => $received, 'discount_amount' => $discount,
            'balance_amount' => 1000 - $received - $discount,
            'financial_category_id' => $category->id, 'chart_account_id' => $account->id,
        ]);
        $payment = AccountReceivableInstallmentPayment::create([
            'company_id' => $company->id, 'account_receivable_installment_id' => $installment->id,
            'payment_date' => '2026-08-15', 'amount' => $received, 'discount_amount' => $discount,
            'financial_account_id' => $bank->id,
        ]);
        $this->assertNotNull(app(CashMovementService::class)->syncForReceivablePayment($payment));

        return [$company, $model, $installment, $payment, $bank, $category];
    }

    private function createCompany(string $name): Company
    {
        $user = User::factory()->create();

        return Company::query()->create([
            'name' => $name,
            'document_number' => fake()->numerify('##############'),
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);
    }

    private function createModelWithLines(int $companyId, string $templateKey): DreModel
    {
        $model = DreModel::query()->create([
            'company_id' => $companyId,
            'name' => 'DRE Padrão',
            'template_key' => $templateKey,
            'is_active' => true,
        ]);

        DreLine::query()->create([
            'dre_model_id' => $model->id,
            'code' => 'RECEITA',
            'name' => 'Receita',
            'line_type' => DreLineType::ACCOUNT_GROUP->value,
            'operation' => DreOperation::ADD->value,
            'sort_order' => 1,
        ]);
        DreLine::query()->create([
            'dre_model_id' => $model->id,
            'code' => 'CUSTOS',
            'name' => 'Custos',
            'line_type' => DreLineType::ACCOUNT_GROUP->value,
            'operation' => DreOperation::SUBTRACT->value,
            'sort_order' => 2,
        ]);

        return $model->fresh();
    }

    private function createChartAccount(int $companyId, string $code, string $name, ChartAccountType $type): ChartAccount
    {
        return ChartAccount::query()->create([
            'company_id' => $companyId,
            'code' => $code,
            'name' => $name,
            'type' => $type->value,
            'is_postable' => true,
            'is_active' => true,
        ]);
    }

    private function createCategory(int $companyId, int $chartAccountId, string $name, bool $receivable, bool $payable): FinancialCategory
    {
        return FinancialCategory::query()->create([
            'company_id' => $companyId,
            'chart_account_id' => $chartAccountId,
            'name' => $name,
            'allow_receivable' => $receivable,
            'allow_payable' => $payable,
            'allow_cash_movement' => true,
            'is_active' => true,
        ]);
    }
}
