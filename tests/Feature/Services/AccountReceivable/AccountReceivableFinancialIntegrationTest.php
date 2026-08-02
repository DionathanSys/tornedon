<?php

namespace Tests\Feature\Services\AccountReceivable;

use App\Enum\AccountReceivable\Status as AccountReceivableStatus;
use App\Enum\Financial\BankStatementImportStatus;
use App\Enum\Financial\CashMovementDirection;
use App\Enum\Financial\FinancialAccountType;
use App\Enum\Invoice\Status as InvoiceStatus;
use App\Enum\Payment\Condition as PaymentCondition;
use App\Enum\Payment\Method as PaymentMethod;
use App\Models\AccountReceivable;
use App\Models\AccountReceivableInstallment;
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
use App\Services\AccountReceivable\AccountReceivableService;
use App\Services\AccountReceivable\Validators\AccountReceivableInstallmentValidator;
use App\Services\Financial\CashMovementService;
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
            'description' => 'Cliente Teste | Doc. AR-001 | Parcela 01',
        ]);

        $payment = $this->service->registerInstallmentPayment($installment, 100, '2026-04-15', [
            'financial_account_id' => $this->financialAccount->id,
        ]);

        $this->assertNotNull($payment, $this->service->getMessage());
        $this->assertDatabaseCount('cash_movements', 1);

        $updated = $this->service->updateInstallmentPayment($payment->fresh(), [
            'amount' => 120,
            'financial_account_id' => $this->financialAccount->id,
            'description' => 'Recebimento parcial renegociado',
        ]);

        $this->assertNotNull($updated, $this->service->getMessage());
        $this->assertDatabaseCount('cash_movements', 1);

        $movement = CashMovement::query()->first();
        $this->assertSame(CashMovementDirection::INFLOW, $movement->direction);
        $this->assertSame(120.0, $movement->amount);
        $this->assertSame(80.0, $installment->fresh()->balance_amount);
        $this->assertSame($this->customer->id, $movement->counterparty_partner_id);
        $this->assertSame('Cliente Teste', data_get($movement->participants_snapshot, 'counterparty_partner_name'));
        $this->assertSame('Cliente Teste', $movement->party_from_label);
        $this->assertSame('Empresa Financeiro AR', $movement->party_to_label);
        $this->assertSame('Recebimento parcela 01 - Recebimento parcial renegociado', $movement->description);
        $this->assertDatabaseHas('audit_entries', [
            'company_id' => $this->company->id,
            'auditable_type' => AccountReceivable::class,
            'auditable_id' => $receivable->id,
            'event' => 'account_receivable.payment_registered',
            'action' => 'payment_registered',
        ]);
    }

    public function test_delete_receipt_removes_cash_movement_when_not_reconciled(): void
    {
        $receivable = AccountReceivable::create([
            'customer_id' => $this->customer->id,
            'company_id' => $this->company->id,
            'invoice_id' => $this->createInvoice()->id,
            'status' => AccountReceivableStatus::PENDING->value,
            'due_date' => '2026-04-15',
            'paid_date' => null,
            'due_amount' => 100,
            'paid_amount' => 0,
            'paid' => false,
            'payment_method' => PaymentMethod::PIX->value,
        ]);

        $installment = AccountReceivableInstallment::create([
            'account_receivable_id' => $receivable->id,
            'company_id' => $this->company->id,
            'sequence_number' => '01',
            'status' => AccountReceivableStatus::PENDING->value,
            'due_date' => '2026-04-15',
            'original_amount' => 100,
            'due_amount' => 100,
            'received_amount' => 0,
            'balance_amount' => 100,
            'financial_category_id' => $this->receivableCategory->id,
        ]);

        $payment = $this->service->registerInstallmentPayment($installment, 100, '2026-04-15', [
            'financial_account_id' => $this->financialAccount->id,
        ]);

        $this->assertNotNull($payment, $this->service->getMessage());
        $this->assertDatabaseCount('cash_movements', 1);

        $this->assertTrue($this->service->deleteInstallmentPayment($payment->fresh()));

        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertSame(100.0, $installment->fresh()->balance_amount);
    }

    public function test_delete_cash_movement_from_receipt_removes_receipt_and_reopens_installment(): void
    {
        $receivable = AccountReceivable::create([
            'customer_id' => $this->customer->id,
            'company_id' => $this->company->id,
            'invoice_id' => $this->createInvoice()->id,
            'status' => AccountReceivableStatus::PENDING->value,
            'due_date' => '2026-04-15',
            'paid_date' => null,
            'due_amount' => 100,
            'paid_amount' => 0,
            'paid' => false,
            'payment_method' => PaymentMethod::PIX->value,
        ]);

        $installment = AccountReceivableInstallment::create([
            'account_receivable_id' => $receivable->id,
            'company_id' => $this->company->id,
            'sequence_number' => '01',
            'status' => AccountReceivableStatus::PENDING->value,
            'due_date' => '2026-04-15',
            'original_amount' => 100,
            'due_amount' => 100,
            'received_amount' => 0,
            'balance_amount' => 100,
            'financial_category_id' => $this->receivableCategory->id,
        ]);

        $payment = $this->service->registerInstallmentPayment($installment, 100, '2026-04-15', [
            'financial_account_id' => $this->financialAccount->id,
        ]);
        $movement = CashMovement::query()->sole();
        $cashMovementService = app(CashMovementService::class);

        $this->assertNotNull($payment, $this->service->getMessage());
        $this->assertTrue($cashMovementService->deleteSafely($movement, $this->user->id), $cashMovementService->getMessage());

        $this->assertDatabaseCount('account_receivable_installment_payments', 0);
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertSame(100.0, $installment->fresh()->balance_amount);
        $this->assertSame(AccountReceivableStatus::PENDING, $installment->fresh()->status);
    }

    public function test_delete_receipt_reverses_cash_movement_when_reconciled(): void
    {
        $receivable = AccountReceivable::create([
            'customer_id' => $this->customer->id,
            'company_id' => $this->company->id,
            'invoice_id' => $this->createInvoice()->id,
            'status' => AccountReceivableStatus::PENDING->value,
            'due_date' => '2026-04-15',
            'paid_date' => null,
            'due_amount' => 100,
            'paid_amount' => 0,
            'paid' => false,
            'payment_method' => PaymentMethod::PIX->value,
        ]);

        $installment = AccountReceivableInstallment::create([
            'account_receivable_id' => $receivable->id,
            'company_id' => $this->company->id,
            'sequence_number' => '01',
            'status' => AccountReceivableStatus::PENDING->value,
            'due_date' => '2026-04-15',
            'original_amount' => 100,
            'due_amount' => 100,
            'received_amount' => 0,
            'balance_amount' => 100,
            'financial_category_id' => $this->receivableCategory->id,
        ]);

        $payment = $this->service->registerInstallmentPayment($installment, 100, '2026-04-15', [
            'financial_account_id' => $this->financialAccount->id,
        ]);
        $movement = CashMovement::query()->sole();
        $import = BankStatementImport::create([
            'company_id' => $this->company->id,
            'financial_account_id' => $this->financialAccount->id,
            'source' => 'test',
            'reference' => 'reconciled-receipt',
            'file_name' => 'test.ofx',
            'status' => BankStatementImportStatus::COMPLETED->value,
            'imported_at' => now(),
            'line_count' => 1,
            'created_by' => $this->user->id,
        ]);
        BankStatementLine::create([
            'bank_statement_import_id' => $import->id,
            'company_id' => $this->company->id,
            'financial_account_id' => $this->financialAccount->id,
            'cash_movement_id' => $movement->id,
            'transaction_date' => '2026-04-15',
            'amount' => 100,
            'description' => 'Recebimento conciliado',
            'reconciliation_status' => 'reconciled',
            'reconciled_at' => now(),
        ]);

        $this->assertNotNull($payment, $this->service->getMessage());
        $this->assertTrue($this->service->deleteInstallmentPayment($payment->fresh()));

        $this->assertDatabaseCount('cash_movements', 2);
        $this->assertNotNull($movement->fresh()->reversed_at);
        $this->assertDatabaseHas('cash_movements', [
            'reversal_of_id' => $movement->id,
            'direction' => CashMovementDirection::OUTFLOW->value,
        ]);
        $this->assertSame(100.0, $installment->fresh()->balance_amount);
    }

    public function test_validator_rejects_category_with_invalid_scope_for_receivable_installment(): void
    {
        $receivable = AccountReceivable::create([
            'customer_id' => $this->customer->id,
            'company_id' => $this->company->id,
            'invoice_id' => $this->createInvoice()->id,
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

    public function test_create_receivable_with_term_mode_generates_consistent_installments(): void
    {
        $receivable = $this->service->create([
            'customer_id' => $this->customer->id,
            'company_id' => $this->company->id,
            'invoice_id' => $this->createInvoice()->id,
            'due_date' => '2026-04-10',
            'due_amount' => 300,
            'payment_method' => PaymentMethod::PIX->value,
            'installment_count' => 3,
            'installment_due_mode' => PaymentCondition::DAYS_15->value,
            'financial_category_id' => $this->receivableCategory->id,
        ], $this->user->id);

        $this->assertNotNull($receivable, $this->service->getMessage());
        $this->assertDatabaseHas('audit_entries', [
            'company_id' => $this->company->id,
            'auditable_type' => AccountReceivable::class,
            'auditable_id' => $receivable->id,
            'actor_user_id' => $this->user->id,
            'event' => 'account_receivable.created',
            'action' => 'created',
        ]);

        $installments = $receivable->fresh()->installments()->orderBy('sequence_number')->get();

        $this->assertCount(3, $installments);
        $this->assertSame(
            ['2026-04-10', '2026-04-25', '2026-05-10'],
            $installments->pluck('due_date')->map(fn ($date) => $date?->format('Y-m-d'))->all()
        );
        $this->assertSame(
            'Cliente Teste | Doc. Sem documento | Parcela 01',
            $installments->first()->description
        );
        $this->assertSame(
            1,
            AuditEntry::query()
                ->where('auditable_type', AccountReceivable::class)
                ->where('auditable_id', $receivable->id)
                ->where('event', 'account_receivable.created')
                ->count()
        );
    }

    public function test_create_receivable_allows_manual_counterparty_and_null_invoice(): void
    {
        $receivable = $this->service->create([
            'customer_id' => null,
            'manual_counterparty_name' => 'Cliente Avulso',
            'is_manual_counterparty' => true,
            'company_id' => $this->company->id,
            'invoice_id' => null,
            'due_date' => '2026-04-10',
            'due_amount' => 180,
            'payment_method' => PaymentMethod::PIX->value,
            'installment_count' => 1,
            'financial_category_id' => $this->receivableCategory->id,
        ], $this->user->id);

        $this->assertNotNull($receivable, $this->service->getMessage());
        $this->assertNull($receivable->customer_id);
        $this->assertNull($receivable->invoice_id);
        $this->assertSame('Cliente Avulso', $receivable->manual_counterparty_name);
        $this->assertSame('Cliente Avulso', $receivable->counterparty_label);
        $this->assertSame(
            'Cliente Avulso | Doc. Sem documento | Parcela 01',
            $receivable->installments()->sole()->description
        );
    }

    public function test_update_and_delete_installment_generate_audit_entries(): void
    {
        $this->actingAs($this->user);

        $receivable = AccountReceivable::create([
            'customer_id' => $this->customer->id,
            'company_id' => $this->company->id,
            'invoice_id' => $this->createInvoice()->id,
            'status' => AccountReceivableStatus::PENDING->value,
            'due_date' => '2026-04-20',
            'paid_date' => null,
            'due_amount' => 300,
            'paid_amount' => 0,
            'paid' => false,
            'payment_method' => PaymentMethod::PIX->value,
            'description' => 'Conta com parcelas auditáveis',
        ]);

        $firstInstallment = AccountReceivableInstallment::create([
            'account_receivable_id' => $receivable->id,
            'company_id' => $this->company->id,
            'sequence_number' => '01',
            'status' => AccountReceivableStatus::PENDING->value,
            'due_date' => '2026-04-20',
            'original_amount' => 150,
            'due_amount' => 150,
            'received_amount' => 0,
            'balance_amount' => 150,
            'financial_category_id' => $this->receivableCategory->id,
            'description' => 'Parcela 01',
        ]);

        $secondInstallment = AccountReceivableInstallment::create([
            'account_receivable_id' => $receivable->id,
            'company_id' => $this->company->id,
            'sequence_number' => '02',
            'status' => AccountReceivableStatus::PENDING->value,
            'due_date' => '2026-05-20',
            'original_amount' => 150,
            'due_amount' => 150,
            'received_amount' => 0,
            'balance_amount' => 150,
            'financial_category_id' => $this->receivableCategory->id,
            'description' => 'Parcela 02',
        ]);

        $updated = $this->service->updateInstallment($firstInstallment, [
            'due_date' => '2026-04-25',
            'due_amount' => 160,
            'original_amount' => 160,
            'financial_category_id' => $this->receivableCategory->id,
            'description' => 'Parcela 01 ajustada',
        ]);

        $this->assertNotNull($updated, $this->service->getMessage());
        $this->assertDatabaseHas('audit_entries', [
            'company_id' => $this->company->id,
            'auditable_type' => AccountReceivable::class,
            'auditable_id' => $receivable->id,
            'event' => 'account_receivable.installment_updated',
            'action' => 'installment_updated',
        ]);

        $deleted = $this->service->deleteInstallment($secondInstallment->fresh());

        $this->assertTrue($deleted, $this->service->getMessage());
        $this->assertDatabaseHas('audit_entries', [
            'company_id' => $this->company->id,
            'auditable_type' => AccountReceivable::class,
            'auditable_id' => $receivable->id,
            'event' => 'account_receivable.installment_deleted',
            'action' => 'installment_deleted',
        ]);
    }
}
