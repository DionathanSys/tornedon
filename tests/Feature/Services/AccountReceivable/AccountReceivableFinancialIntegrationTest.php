<?php

namespace Tests\Feature\Services\AccountReceivable;

use App\Enum\AccountReceivable\Status as AccountReceivableStatus;
use App\Enum\Financial\CashMovementDirection;
use App\Enum\Financial\FinancialAccountType;
use App\Enum\Invoice\Status as InvoiceStatus;
use App\Enum\Payment\Method as PaymentMethod;
use App\Models\AccountReceivable;
use App\Models\AccountReceivableInstallment;
use App\Models\CashMovement;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\Invoice;
use App\Models\Partner;
use App\Models\User;
use App\Services\AccountReceivable\AccountReceivableService;
use App\Services\AccountReceivable\Validators\AccountReceivableInstallmentValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AccountReceivableFinancialIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private AccountReceivableService $service;
    private User $user;
    private Company $company;
    private Partner $customer;
    private FinancialAccount $financialAccount;
    private FinancialCategory $receivableCategory;
    private FinancialCategory $payableOnlyCategory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(AccountReceivableService::class);
        $this->user = User::factory()->create();

        $this->company = Company::create([
            'name' => 'Empresa Financeiro AR',
            'document_number' => '12345678000188',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $this->user->id,
        ]);

        $this->customer = Partner::create([
            'name' => 'Cliente Teste',
            'document_type' => 'CPF',
            'document_number' => '12345678901',
            'created_by' => $this->user->id,
        ]);

        $this->financialAccount = FinancialAccount::create([
            'company_id' => $this->company->id,
            'name' => 'Caixa Principal',
            'type' => FinancialAccountType::CASH->value,
            'opening_balance' => 0,
            'is_active' => true,
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);

        $receitaPai = FinancialCategory::create([
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
            'parent_id' => $receitaPai->id,
            'name' => 'Servicos',
            'allow_receivable' => true,
            'allow_cash_movement' => true,
            'is_active' => true,
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);

        $this->payableOnlyCategory = FinancialCategory::create([
            'company_id' => $this->company->id,
            'name' => 'Despesa Exclusiva',
            'allow_payable' => true,
            'allow_receivable' => false,
            'allow_cash_movement' => true,
            'is_active' => true,
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);
    }

    public function test_update_receipt_keeps_single_cash_movement_and_refreshes_amount(): void
    {
        $receivable = AccountReceivable::create([
            'customer_id' => $this->customer->id,
            'company_id' => $this->company->id,
            'invoice_id' => $this->createInvoice()->id,
            'sequence_number' => '01',
            'status' => AccountReceivableStatus::PENDING->value,
            'due_date' => '2026-04-15',
            'paid_date' => null,
            'due_amount' => 200,
            'paid_amount' => 0,
            'paid' => false,
            'payment_method' => PaymentMethod::PIX->value,
            'description' => 'Conta teste AR',
        ]);

        $installment = AccountReceivableInstallment::create([
            'account_receivable_id' => $receivable->id,
            'company_id' => $this->company->id,
            'sequence_number' => '01',
            'status' => AccountReceivableStatus::PENDING->value,
            'due_date' => '2026-04-15',
            'original_amount' => 200,
            'due_amount' => 200,
            'received_amount' => 0,
            'balance_amount' => 200,
            'financial_category_id' => $this->receivableCategory->id,
        ]);

        $payment = $this->service->registerInstallmentPayment($installment, 100, '2026-04-15', [
            'financial_account_id' => $this->financialAccount->id,
        ]);

        $this->assertNotNull($payment, $this->service->getMessage());
        $this->assertDatabaseCount('cash_movements', 1);

        $updated = $this->service->updateInstallmentPayment($payment->fresh(), [
            'amount' => 120,
            'financial_account_id' => $this->financialAccount->id,
        ]);

        $this->assertNotNull($updated, $this->service->getMessage());
        $this->assertDatabaseCount('cash_movements', 1);

        $movement = CashMovement::query()->first();
        $this->assertSame(CashMovementDirection::INFLOW, $movement->direction);
        $this->assertSame(120.0, $movement->amount);
        $this->assertSame(80.0, $installment->fresh()->balance_amount);
    }

    public function test_validator_rejects_category_with_invalid_scope_for_receivable_installment(): void
    {
        $receivable = AccountReceivable::create([
            'customer_id' => $this->customer->id,
            'company_id' => $this->company->id,
            'invoice_id' => $this->createInvoice()->id,
            'sequence_number' => '01',
            'status' => AccountReceivableStatus::PENDING->value,
            'due_date' => '2026-04-10',
            'paid_date' => null,
            'due_amount' => 100,
            'paid_amount' => 0,
            'paid' => false,
            'payment_method' => PaymentMethod::PIX->value,
        ]);

        $this->expectException(ValidationException::class);

        AccountReceivableInstallmentValidator::validateCreate([
            'account_receivable_id' => $receivable->id,
            'company_id' => $this->company->id,
            'sequence_number' => '01',
            'due_date' => '2026-04-10',
            'due_amount' => 100,
            'status' => AccountReceivableStatus::PENDING->value,
            'financial_category_id' => $this->payableOnlyCategory->id,
        ]);
    }

    private function createInvoice(): Invoice
    {
        return Invoice::create([
            'customer_id' => $this->customer->id,
            'company_id' => $this->company->id,
            'invoice_number' => (string) random_int(100000, 999999),
            'invoice_date' => '2026-04-08',
            'status' => InvoiceStatus::CONFIRMED->value,
            'pending' => false,
            'confirmed' => true,
            'created_by' => $this->user->id,
        ]);
    }
}
