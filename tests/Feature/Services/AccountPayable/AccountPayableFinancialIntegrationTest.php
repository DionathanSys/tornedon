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
            'description' => 'Fornecedor Teste | Doc. AP-001 | Parcela 01',
            'notes' => 'Parcela AP',
        ]);

        $payment = $this->service->registerInstallmentPayment($installment, 150, '2026-04-10', [
            'financial_account_id' => $this->financialAccount->id,
            'description' => 'Pagamento PIX fornecedor abril',
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
        $this->assertSame($this->supplier->id, $movement->counterparty_partner_id);
        $this->assertSame('Fornecedor Teste', data_get($movement->participants_snapshot, 'counterparty_partner_name'));
        $this->assertSame('Empresa Financeiro AP', $movement->party_from_label);
        $this->assertSame('Fornecedor Teste', $movement->party_to_label);
        $this->assertSame('Pagamento parcela 01 - Pagamento PIX fornecedor abril', $movement->description);

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

    public function test_create_payable_with_custom_interval_generates_installments_and_default_descriptions(): void
    {
        $payable = $this->service->create([
            'supplier_id' => $this->supplier->id,
            'company_id' => $this->company->id,
            'due_date' => '2026-04-10',
            'due_amount' => 300,
            'payment_method' => PaymentMethod::PIX->value,
            'installment_count' => 3,
            'installment_due_mode' => 'custom_interval_days',
            'installment_interval_days' => 15,
            'financial_category_id' => $this->payableCategory->id,
        ], $this->user->id);

        $this->assertNotNull($payable, $this->service->getMessage());

        $installments = $payable->fresh()->installments()->orderBy('sequence_number')->get();

        $this->assertCount(3, $installments);
        $this->assertSame(
            ['2026-04-10', '2026-04-25', '2026-05-10'],
            $installments->pluck('due_date')->map(fn ($date) => $date?->format('Y-m-d'))->all()
        );
        $this->assertSame(
            'Fornecedor Teste | Doc. Sem documento | Parcela 01',
            $installments->first()->description
        );
    }

    public function test_create_payable_can_auto_register_payments_when_effective(): void
    {
        $payable = $this->service->create([
            'supplier_id' => $this->supplier->id,
            'company_id' => $this->company->id,
            'due_date' => '2026-04-10',
            'due_amount' => 200,
            'payment_method' => PaymentMethod::PIX->value,
            'installment_count' => 2,
            'installment_due_mode' => 'custom_interval_days',
            'installment_interval_days' => 30,
            'financial_category_id' => $this->payableCategory->id,
            'is_effective' => true,
            'auto_register_payment_on_due_date' => true,
            'auto_payment_financial_account_id' => $this->financialAccount->id,
        ], $this->user->id);

        $this->assertNotNull($payable, $this->service->getMessage());

        $payable->refresh();

        $this->assertTrue($payable->is_effective);
        $this->assertTrue($payable->paid);
        $this->assertSame(AccountPayableStatus::PAID, $payable->status);
        $this->assertCount(2, $payable->payments);
        $this->assertDatabaseCount('cash_movements', 2);

        $paymentDates = $payable->payments
            ->sortBy('payment_date')
            ->pluck('payment_date')
            ->map(fn ($date) => $date?->format('Y-m-d'))
            ->values()
            ->all();

        $this->assertSame(['2026-04-10', '2026-05-10'], $paymentDates);
    }

    public function test_create_payable_rejects_auto_register_when_not_effective(): void
    {
        $payable = $this->service->create([
            'supplier_id' => $this->supplier->id,
            'company_id' => $this->company->id,
            'due_date' => '2026-04-10',
            'due_amount' => 100,
            'payment_method' => PaymentMethod::PIX->value,
            'installment_count' => 1,
            'financial_category_id' => $this->payableCategory->id,
            'is_effective' => false,
            'auto_register_payment_on_due_date' => true,
            'auto_payment_financial_account_id' => $this->financialAccount->id,
        ], $this->user->id);

        $this->assertNull($payable);
        $this->assertArrayHasKey('auto_register_payment_on_due_date', $this->service->getErrors());
    }
}
