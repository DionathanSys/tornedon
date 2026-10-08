<?php

namespace Tests\Feature\Console;

use App\Enum\AccountReceivable\Status;
use App\Enum\Financial\FinancialAccountType;
use App\Enum\Payment\Method;
use App\Models\AccountReceivable;
use App\Models\CardInstitution;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\User;
use App\Services\AccountReceivable\AccountReceivableService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcessAutoReceivableReceiptsCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private FinancialAccount $account;

    private array $payload;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->company = Company::create(['name' => 'Empresa Cartão', 'document_number' => '12345678000191', 'address' => ['state' => 'SP'], 'created_by' => $this->user->id]);
        $this->account = FinancialAccount::create([
            'company_id' => $this->company->id, 'name' => 'Banco', 'type' => FinancialAccountType::BANK->value,
            'opening_balance' => 0, 'is_active' => true, 'created_by' => $this->user->id,
        ]);
        $category = FinancialCategory::create([
            'company_id' => $this->company->id, 'name' => 'Vendas', 'is_active' => true,
            'allow_receivable' => true, 'allow_cash_movement' => true,
        ]);
        $institution = CardInstitution::create([
            'company_id' => $this->company->id, 'name' => 'Stone', 'settlement_days' => 30,
        ]);
        $this->payload = [
            'company_id' => $this->company->id, 'manual_counterparty_name' => 'Cliente',
            'payment_method' => Method::CREDIT_CARD->value, 'card_payment_profile_id' => $institution->id,
            'payment_date' => '2026-05-04', 'due_date' => '2026-05-04', 'due_amount' => 1000,
            'financial_category_id' => $category->id, 'installment_count' => 2,
            'installment_due_mode' => 'custom_interval_days', 'installment_interval_days' => 30,
            'auto_register_receipt_on_due_date' => true, 'auto_receipt_financial_account_id' => $this->account->id,
        ];
    }

    private function createReceivable(array $overrides = []): AccountReceivable
    {
        $service = app(AccountReceivableService::class);
        $receivable = $service->create([...$this->payload, ...$overrides], $this->user->id);
        $this->assertNotNull($receivable, json_encode($service->getErrors()));

        return $receivable;
    }

    public function test_partial_advance_leaves_balance_for_due_date_and_does_not_duplicate_receipts(): void
    {
        $receivable = $this->createReceivable();
        $installments = $receivable->installments()->orderBy('sequence_number')->get();
        $this->assertSame('2026-06-03', $installments[0]->due_date->toDateString());
        $this->assertSame('2026-07-03', $installments[1]->due_date->toDateString());
        $service = app(AccountReceivableService::class);
        $advance = $service->registerInstallmentPayment($installments[0], 200, '2026-05-15', [
            'financial_account_id' => $this->account->id, 'user_id' => $this->user->id,
        ]);
        $this->assertNotNull($advance, json_encode($service->getErrors()));
        $this->assertSame(300.0, (float) $installments[0]->fresh()->balance_amount);
        $this->assertSame(Status::PARTIALLY_RECEIVED, $receivable->fresh()->status);

        $this->artisan('account-receivables:process-auto-receipts', ['--date' => '2026-06-02'])->assertExitCode(0);
        $this->assertDatabaseCount('account_receivable_installment_payments', 1);
        $this->artisan('account-receivables:process-auto-receipts', ['--date' => '2026-06-03'])->assertExitCode(0);
        $this->assertSame(0.0, (float) $installments[0]->fresh()->balance_amount);
        $this->assertSame(500.0, (float) $installments[1]->fresh()->balance_amount);
        $this->assertSame(500.0, (float) $receivable->fresh()->paid_amount);
        $this->assertDatabaseHas('account_receivable_installment_payments', ['amount' => 30000, 'payment_date' => '2026-06-03 00:00:00']);
        $this->artisan('account-receivables:process-auto-receipts', ['--date' => '2026-06-03'])->assertExitCode(0);
        $this->assertDatabaseCount('account_receivable_installment_payments', 2);
        $this->assertDatabaseCount('cash_movements', 2);
        $this->artisan('account-receivables:process-auto-receipts', ['--date' => '2026-07-03'])->assertExitCode(0);
        $this->assertTrue($receivable->fresh()->paid);
        $this->assertSame(1000.0, (float) $this->account->fresh()->current_balance);
    }

    public function test_disabled_automatic_receipt_is_not_processed(): void
    {
        $receivable = $this->createReceivable(['auto_register_receipt_on_due_date' => false]);
        $this->artisan('account-receivables:process-auto-receipts', ['--date' => '2026-06-03'])->assertExitCode(0);
        $this->assertFalse($receivable->fresh()->paid);
        $this->assertDatabaseCount('account_receivable_installment_payments', 0);
    }

    public function test_full_advance_is_skipped_on_due_date(): void
    {
        $receivable = $this->createReceivable(['installment_count' => 1]);
        $service = app(AccountReceivableService::class);
        $payment = $service->registerInstallmentPayment($receivable->installments()->first(), 1000, '2026-05-15', [
            'financial_account_id' => $this->account->id, 'user_id' => $this->user->id,
        ]);
        $this->assertNotNull($payment, json_encode($service->getErrors()));
        $this->artisan('account-receivables:process-auto-receipts', ['--date' => '2026-06-03'])->assertExitCode(0);
        $this->assertDatabaseCount('account_receivable_installment_payments', 1);
        $this->assertDatabaseCount('cash_movements', 1);
    }

    public function test_automatic_receipt_requires_active_account_of_same_company(): void
    {
        $service = app(AccountReceivableService::class);
        $this->account->update(['is_active' => false]);
        $this->assertNull($service->create($this->payload, $this->user->id));
        $this->assertArrayHasKey('auto_receipt_financial_account_id', $service->getErrors());

        $other = Company::create(['name' => 'Outra', 'document_number' => '12345678000192', 'address' => ['state' => 'SP'], 'created_by' => $this->user->id]);
        $this->account->update(['is_active' => true, 'company_id' => $other->id]);
        $this->assertNull($service->create($this->payload, $this->user->id));
        $this->assertArrayHasKey('auto_receipt_financial_account_id', $service->getErrors());
    }

    public function test_invalidated_account_prevents_payment_and_cash_movement(): void
    {
        $this->createReceivable();
        $this->account->update(['is_active' => false]);
        $this->artisan('account-receivables:process-auto-receipts', ['--date' => '2026-06-03'])->assertExitCode(1);
        $this->assertDatabaseCount('account_receivable_installment_payments', 0);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_editing_receipt_settings_preserves_historical_terms_and_fees(): void
    {
        $receivable = $this->createReceivable(['auto_register_receipt_on_due_date' => false]);
        $snapshot = ['name' => 'Stone', 'settlement_days' => 30, 'fee_percent' => 3];
        $receivable->update(['card_fee_amount' => 30, 'net_amount' => 970, 'card_rule_snapshot' => $snapshot]);
        $receivable->cardInstitution->update(['settlement_days' => 10]);
        $service = app(AccountReceivableService::class);
        $updated = $service->update($receivable->fresh(), ['auto_register_receipt_on_due_date' => true], $this->user->id);

        $this->assertNotNull($updated, json_encode($service->getErrors()));
        $this->assertTrue($updated->auto_register_receipt_on_due_date);
        $this->assertSame(30.0, (float) $updated->card_fee_amount);
        $this->assertSame(970.0, (float) $updated->net_amount);
        $this->assertSame($snapshot, $updated->card_rule_snapshot);
        $this->assertSame('2026-06-03', $updated->installments()->first()->due_date->toDateString());
    }

    public function test_changing_card_terms_updates_open_installment_dates(): void
    {
        $receivable = $this->createReceivable();
        $institution = CardInstitution::create(['company_id' => $this->company->id, 'name' => 'Rede', 'settlement_days' => 15]);
        $service = app(AccountReceivableService::class);
        $updated = $service->update($receivable, ['card_payment_profile_id' => $institution->id], $this->user->id);

        $this->assertNotNull($updated, json_encode($service->getErrors()));
        $this->assertSame('2026-05-19', $updated->due_date->toDateString());
        $installments = $updated->installments()->orderBy('sequence_number')->get();
        $this->assertSame('2026-05-19', $installments[0]->due_date->toDateString());
        $this->assertSame('2026-06-18', $installments[1]->due_date->toDateString());
    }

    public function test_advance_cannot_exceed_open_balance(): void
    {
        $receivable = $this->createReceivable();
        $service = app(AccountReceivableService::class);
        $this->assertNull($service->registerInstallmentPayment($receivable->installments()->first(), 600, '2026-05-15', [
            'financial_account_id' => $this->account->id, 'user_id' => $this->user->id,
        ]));
        $this->assertDatabaseCount('account_receivable_installment_payments', 0);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_cancelled_receivable_is_not_processed(): void
    {
        $receivable = $this->createReceivable();
        $receivable->update(['status' => Status::CANCELLED]);
        $this->artisan('account-receivables:process-auto-receipts', ['--date' => '2026-06-03'])->assertExitCode(0);
        $this->assertDatabaseCount('account_receivable_installment_payments', 0);
    }
}
