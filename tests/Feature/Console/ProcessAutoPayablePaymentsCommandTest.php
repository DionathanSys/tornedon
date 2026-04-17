<?php

namespace Tests\Feature\Console;

use App\Enum\AccountPayable\Status as AccountPayableStatus;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcessAutoPayablePaymentsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_registers_due_installments_and_is_idempotent(): void
    {
        $user = User::factory()->create();

        $company = Company::create([
            'name' => 'Empresa Auto Pay',
            'document_number' => '12345678000144',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);

        $supplier = Partner::create([
            'name' => 'Fornecedor Auto',
            'document_type' => 'CNPJ',
            'document_number' => '12345678000155',
            'created_by' => $user->id,
        ]);

        $financialAccount = FinancialAccount::create([
            'company_id' => $company->id,
            'name' => 'Conta Auto',
            'type' => FinancialAccountType::BANK->value,
            'opening_balance' => 1000,
            'is_active' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $categoryParent = FinancialCategory::create([
            'company_id' => $company->id,
            'name' => 'Despesas',
            'allow_payable' => false,
            'allow_cash_movement' => false,
            'is_active' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $category = FinancialCategory::create([
            'company_id' => $company->id,
            'parent_id' => $categoryParent->id,
            'name' => 'Fornecedores',
            'allow_payable' => true,
            'allow_cash_movement' => true,
            'is_active' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $payable = AccountPayable::create([
            'supplier_id' => $supplier->id,
            'company_id' => $company->id,
            'sequence_number' => '01',
            'status' => AccountPayableStatus::PENDING->value,
            'due_date' => '2026-04-17',
            'paid_date' => null,
            'due_amount' => 100,
            'paid_amount' => 0,
            'description' => 'Conta automatica',
            'is_effective' => true,
            'auto_register_payment_on_due_date' => true,
            'auto_payment_financial_account_id' => $financialAccount->id,
            'paid' => false,
            'payment_method' => PaymentMethod::PIX->value,
        ]);

        AccountPayableInstallment::create([
            'account_payable_id' => $payable->id,
            'company_id' => $company->id,
            'sequence_number' => '01',
            'status' => AccountPayableStatus::PENDING->value,
            'due_date' => '2026-04-17',
            'original_amount' => 100,
            'due_amount' => 100,
            'paid_amount' => 0,
            'balance_amount' => 100,
            'financial_category_id' => $category->id,
            'description' => 'Fornecedor Auto | Doc. AP-100 | Parcela 01',
        ]);

        $this->artisan('account-payables:process-auto-payments', ['--date' => '2026-04-17'])
            ->assertExitCode(0);

        $payable->refresh();

        $this->assertTrue($payable->paid);
        $this->assertSame(AccountPayableStatus::PAID, $payable->status);
        $this->assertDatabaseCount('account_payable_installment_payments', 1);
        $this->assertDatabaseCount('cash_movements', 1);

        $this->artisan('account-payables:process-auto-payments', ['--date' => '2026-04-17'])
            ->assertExitCode(0);

        $this->assertDatabaseCount('account_payable_installment_payments', 1);
        $this->assertDatabaseCount('cash_movements', 1);

        $movement = CashMovement::query()->first();

        $this->assertSame('Pagamento parcela 01 - Fornecedor Auto | Doc. AP-100 | Parcela 01', $movement->description);
    }
}
