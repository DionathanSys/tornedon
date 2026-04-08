<?php

namespace Tests\Feature\Services\AccountPayable;

use App\Enum\AccountPayable\Status as AccountPayableStatus;
use App\Enum\Financial\CashMovementDirection;
use App\Enum\Financial\FinancialAccountType;
use App\Enum\Payment\Method as PaymentMethod;
use App\Models\AccountPayable;
use App\Models\AccountPayableInstallment;
use App\Models\CashMovement;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\Partner;
use App\Models\User;
use App\Services\AccountPayable\AccountPayableService;
use App\Services\AccountPayable\Validators\AccountPayableInstallmentValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AccountPayableFinancialIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private AccountPayableService $service;
    private User $user;
    private Company $company;
    private Partner $supplier;
    private FinancialAccount $financialAccount;
    private FinancialCategory $payableCategory;
    private FinancialCategory $payableParentCategory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(AccountPayableService::class);
        $this->user = User::factory()->create();

        $this->company = Company::create([
            'name' => 'Empresa Financeiro AP',
            'document_number' => '12345678000199',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $this->user->id,
        ]);

        $this->supplier = Partner::create([
            'name' => 'Fornecedor Teste',
            'document_type' => 'CNPJ',
            'document_number' => '12345678000155',
            'created_by' => $this->user->id,
        ]);

        $this->financialAccount = FinancialAccount::create([
            'company_id' => $this->company->id,
            'name' => 'Conta Banco',
            'type' => FinancialAccountType::BANK->value,
            'opening_balance' => 1000,
            'is_active' => true,
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);

        $this->payableParentCategory = FinancialCategory::create([
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
            'parent_id' => $this->payableParentCategory->id,
            'name' => 'Fornecedores',
            'allow_payable' => true,
            'allow_cash_movement' => true,
            'is_active' => true,
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);
    }

    public function test_register_payment_creates_cash_movement_and_delete_reverses_it(): void
    {
        $payable = AccountPayable::create([
            'supplier_id' => $this->supplier->id,
            'company_id' => $this->company->id,
            'sequence_number' => '01',
            'status' => AccountPayableStatus::PENDING->value,
            'due_date' => '2026-04-10',
            'paid_date' => null,
            'due_amount' => 150,
            'paid_amount' => 0,
            'paid' => false,
            'payment_method' => PaymentMethod::PIX->value,
            'description' => 'Conta teste AP',
        ]);

        $installment = AccountPayableInstallment::create([
            'account_payable_id' => $payable->id,
            'company_id' => $this->company->id,
            'sequence_number' => '01',
            'status' => AccountPayableStatus::PENDING->value,
            'due_date' => '2026-04-10',
            'original_amount' => 150,
            'due_amount' => 150,
            'paid_amount' => 0,
            'balance_amount' => 150,
            'financial_category_id' => $this->payableCategory->id,
            'notes' => 'Parcela AP',
        ]);

        $payment = $this->service->registerInstallmentPayment($installment, 150, '2026-04-10', [
            'financial_account_id' => $this->financialAccount->id,
            'notes' => 'Pagamento total',
        ]);

        $this->assertNotNull($payment, $this->service->getMessage());
        $this->assertDatabaseCount('cash_movements', 1);
        $this->assertSame(0.0, $installment->fresh()->balance_amount);

        $movement = CashMovement::query()->first();
        $this->assertSame(CashMovementDirection::OUTFLOW, $movement->direction);
        $this->assertSame($payment->id, $movement->origin_id);
        $this->assertSame($this->financialAccount->id, $movement->financial_account_id);
        $this->assertSame($this->payableCategory->id, $movement->financial_category_id);

        $this->assertTrue($this->service->deleteInstallmentPayment($payment->fresh()));
        $this->assertDatabaseCount('cash_movements', 2);

        $original = CashMovement::query()
            ->where('origin_type', $payment::class)
            ->where('origin_id', $payment->id)
            ->first();

        $reversal = CashMovement::query()
            ->where('reversal_of_id', $original->id)
            ->first();

        $this->assertNotNull($original?->reversed_at);
        $this->assertNotNull($reversal);
        $this->assertSame(CashMovementDirection::INFLOW, $reversal->direction);
        $this->assertSame(150.0, $installment->fresh()->balance_amount);
    }

    public function test_validator_rejects_parent_category_for_payable_installment(): void
    {
        $payable = AccountPayable::create([
            'supplier_id' => $this->supplier->id,
            'company_id' => $this->company->id,
            'sequence_number' => '01',
            'status' => AccountPayableStatus::PENDING->value,
            'due_date' => '2026-04-10',
            'paid_date' => null,
            'due_amount' => 100,
            'paid_amount' => 0,
            'paid' => false,
            'payment_method' => PaymentMethod::PIX->value,
        ]);

        $this->expectException(ValidationException::class);

        AccountPayableInstallmentValidator::validateCreate([
            'account_payable_id' => $payable->id,
            'company_id' => $this->company->id,
            'sequence_number' => '01',
            'due_date' => '2026-04-10',
            'due_amount' => 100,
            'status' => AccountPayableStatus::PENDING->value,
            'financial_category_id' => $this->payableParentCategory->id,
        ]);
    }
}
