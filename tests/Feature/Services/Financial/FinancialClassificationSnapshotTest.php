<?php

namespace Tests\Feature\Services\Financial;

use App\Enum\AccountPayable\Status as PayableStatus;
use App\Enum\Financial\CashMovementDirection;
use App\Enum\Financial\ChartAccountType;
use App\Enum\Financial\FinancialAccountType;
use App\Models\AccountPayableInstallment;
use App\Models\ChartAccount;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\Partner;
use App\Models\ResultCenter;
use App\Models\User;
use App\Services\AccountPayable\AccountPayableService;
use App\Services\Financial\CashMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialClassificationSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private FinancialAccount $financialAccount;

    private ChartAccount $chartAccount;

    private FinancialCategory $category;

    private CostCenter $costCenter;

    private ResultCenter $resultCenter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->company = Company::query()->create([
            'name' => 'Empresa Snapshot',
            'document_number' => '12345678000199',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $this->user->id,
        ]);
        $this->financialAccount = FinancialAccount::query()->create([
            'company_id' => $this->company->id,
            'name' => 'Conta Movimento',
            'type' => FinancialAccountType::BANK->value,
            'opening_balance' => 0,
            'is_active' => true,
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);
        $this->chartAccount = ChartAccount::query()->create([
            'company_id' => $this->company->id,
            'code' => '2.01.01',
            'name' => 'Energia',
            'type' => ChartAccountType::EXPENSE->value,
            'is_postable' => true,
            'is_active' => true,
        ]);
        $this->costCenter = CostCenter::query()->create([
            'company_id' => $this->company->id,
            'code' => 'ADM',
            'name' => 'Administrativo',
            'is_active' => true,
        ]);
        $this->resultCenter = ResultCenter::query()->create([
            'company_id' => $this->company->id,
            'code' => 'OP',
            'name' => 'Operações',
            'is_active' => true,
        ]);
        $this->category = FinancialCategory::query()->create([
            'company_id' => $this->company->id,
            'chart_account_id' => $this->chartAccount->id,
            'name' => 'Energia Elétrica',
            'allow_payable' => true,
            'allow_cash_movement' => true,
            'is_active' => true,
        ]);
    }

    public function test_payable_installment_copies_category_chart_account_and_defaults_competence_to_due_date(): void
    {
        $supplier = Partner::query()->create([
            'name' => 'Fornecedor Energia',
            'document_type' => 'CNPJ',
            'document_number' => '12345678000155',
            'created_by' => $this->user->id,
        ]);

        $service = app(AccountPayableService::class);
        $payable = $service->create([
            'supplier_id' => $supplier->id,
            'company_id' => $this->company->id,
            'due_date' => '2026-08-15',
            'due_amount' => 500,
            'financial_category_id' => $this->category->id,
            'cost_center_id' => $this->costCenter->id,
            'result_center_id' => $this->resultCenter->id,
            'description' => 'Energia agosto',
            'installment_count' => 1,
        ], $this->user->id);

        $this->assertNotNull($payable, $service->getMessage());

        $installment = AccountPayableInstallment::query()->where('account_payable_id', $payable->id)->first();

        $this->assertSame($this->category->id, $installment->financial_category_id);
        $this->assertSame($this->chartAccount->id, $installment->chart_account_id);
        $this->assertSame($this->costCenter->id, $installment->cost_center_id);
        $this->assertSame($this->resultCenter->id, $installment->result_center_id);
        $this->assertSame('2026-08-15', $installment->competence_date->toDateString());
    }

    public function test_payment_can_override_installment_competence_and_cash_movement_uses_installment_classification(): void
    {
        $payable = \App\Models\AccountPayable::query()->create([
            'company_id' => $this->company->id,
            'manual_counterparty_name' => 'Fornecedor Avulso',
            'sequence_number' => '01',
            'status' => PayableStatus::PENDING->value,
            'due_date' => '2026-08-15',
            'due_amount' => 500,
            'paid_amount' => 0,
            'paid' => false,
        ]);
        $installment = AccountPayableInstallment::query()->create([
            'account_payable_id' => $payable->id,
            'company_id' => $this->company->id,
            'sequence_number' => '01',
            'status' => PayableStatus::PENDING->value,
            'due_date' => '2026-08-15',
            'competence_date' => '2026-08-15',
            'original_amount' => 500,
            'due_amount' => 500,
            'paid_amount' => 0,
            'balance_amount' => 500,
            'financial_category_id' => $this->category->id,
            'chart_account_id' => $this->chartAccount->id,
            'cost_center_id' => $this->costCenter->id,
            'result_center_id' => $this->resultCenter->id,
        ]);

        $service = app(AccountPayableService::class);
        $payment = $service->registerInstallmentPayment($installment, 500, '2026-09-05', [
            'financial_account_id' => $this->financialAccount->id,
            'competence_date' => '2026-09-05',
        ]);

        $this->assertNotNull($payment, $service->getMessage());
        $movement = \App\Models\CashMovement::query()->first();

        $this->assertSame('2026-09-05', $installment->fresh()->competence_date->toDateString());
        $this->assertSame($this->chartAccount->id, $movement->chart_account_id);
        $this->assertSame($this->costCenter->id, $movement->cost_center_id);
        $this->assertSame($this->resultCenter->id, $movement->result_center_id);
        $this->assertSame('2026-09-05', $movement->competence_date->toDateString());
    }

    public function test_manual_cash_movement_copies_category_chart_account_and_defaults_competence_to_transaction_date(): void
    {
        $service = app(CashMovementService::class);

        $movement = $service->createManual([
            'company_id' => $this->company->id,
            'financial_account_id' => $this->financialAccount->id,
            'financial_category_id' => $this->category->id,
            'cost_center_id' => $this->costCenter->id,
            'result_center_id' => $this->resultCenter->id,
            'direction' => CashMovementDirection::OUTFLOW->value,
            'transaction_date' => '2026-08-20',
            'amount' => 100,
            'description' => 'Movimento manual energia',
        ], $this->user->id);

        $this->assertNotNull($movement, $service->getMessage());
        $this->assertSame($this->chartAccount->id, $movement->chart_account_id);
        $this->assertSame($this->costCenter->id, $movement->cost_center_id);
        $this->assertSame($this->resultCenter->id, $movement->result_center_id);
        $this->assertSame('2026-08-20', $movement->competence_date->toDateString());
    }
}
