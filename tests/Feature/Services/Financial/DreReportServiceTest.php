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
use App\Models\AccountPayable;
use App\Models\AccountPayableInstallment;
use App\Models\AccountReceivable;
use App\Models\AccountReceivableInstallment;
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
use App\Services\Financial\Dre\GenerateDreReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
