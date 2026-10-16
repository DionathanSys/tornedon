<?php

namespace Tests\Feature\Services\Financial;

use App\Models\CashMovement;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\User;
use App\Services\AccountPayable\AccountPayableService;
use App\Services\AccountPayable\Actions\Installment\SyncAccountPayableStatusFromInstallmentsAction;
use App\Services\AccountReceivable\AccountReceivableService;
use App\Services\Financial\CashMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FinancialIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private FinancialAccount $bank;

    public static function domains(): array
    {
        return [['receivable'], ['payable']];
    }

    private function account(string $domain, float $amount = 1000): array
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $company = Company::create(['name' => 'Integridade', 'document_number' => '12345678000188', 'address' => ['city' => 'São Paulo', 'state' => 'SP'], 'created_by' => $user->id]);
        $this->bank = FinancialAccount::create([
            'company_id' => $company->id, 'name' => 'Banco', 'type' => 'bank', 'opening_balance' => 0, 'is_active' => true,
        ]);
        $parent = FinancialCategory::create(['company_id' => $company->id, 'name' => 'Grupo', 'is_active' => true]);
        $category = FinancialCategory::create([
            'company_id' => $company->id, 'parent_id' => $parent->id, 'name' => 'Categoria',
            'allow_receivable' => true, 'allow_payable' => true, 'allow_cash_movement' => true, 'is_active' => true,
        ]);
        $service = $domain === 'receivable' ? app(AccountReceivableService::class) : app(AccountPayableService::class);
        $account = $service->create([
            'company_id' => $company->id, 'manual_counterparty_name' => 'Contraparte',
            'is_manual_counterparty' => true, 'due_date' => '2026-10-16', 'due_amount' => $amount,
            'payment_method' => 'pix', 'financial_category_id' => $category->id,
        ], $user->id);
        $this->assertNotNull($account, $service->getMessageUser());

        return [$service, $account, $account->installments()->sole()];
    }

    #[DataProvider('domains')]
    public function test_interest_and_discount_settle_the_adjusted_obligation(string $domain): void
    {
        [$service, $account, $installment] = $this->account($domain);
        $payment = $service->registerInstallmentPayment($installment, 1100, '2026-10-16', [
            'interest_amount' => 80, 'fine_amount' => 20, 'financial_account_id' => $this->bank->id,
        ]);
        $this->assertNotNull($payment, $service->getMessageUser());
        $this->assertSame(0.0, $installment->fresh()->balance_amount);
        $this->assertTrue($account->fresh()->paid);
        $this->assertSame(1100.0, CashMovement::query()->sole()->amount);

        $updated = $service->updateInstallmentPayment($payment, [
            'amount' => 900, 'interest_amount' => 0, 'fine_amount' => 0, 'discount_amount' => 100,
        ]);
        $this->assertNotNull($updated, $service->getMessageUser());
        $this->assertSame(0.0, $installment->fresh()->balance_amount);
        $this->assertTrue($account->fresh()->paid);
        $this->assertSame(900.0, CashMovement::query()->sole()->amount);
    }

    #[DataProvider('domains')]
    public function test_excess_and_invalid_charges_do_not_change_the_obligation(string $domain): void
    {
        [$service, $account, $installment] = $this->account($domain);
        foreach ([[1100, []], [900, ['discount_amount' => 200]], [1, ['interest_amount' => -1]], [0.001, []]] as [$amount, $extra]) {
            $this->assertNull($service->registerInstallmentPayment($installment, $amount, '2026-10-16', $extra));
            $this->assertTrue($service->hasError());
        }
        $this->assertSame(0, $installment->payments()->count());
        $this->assertSame(1000.0, $installment->fresh()->balance_amount);
        $this->assertFalse($account->fresh()->paid);
    }

    #[DataProvider('domains')]
    public function test_partial_payment_prevents_header_deletion_and_excess_on_edit(string $domain): void
    {
        [$service, $account, $installment] = $this->account($domain);
        $payment = $service->registerInstallmentPayment($installment, 300, '2026-10-16', ['financial_account_id' => $this->bank->id]);
        $this->assertNotNull($payment, $service->getMessageUser());
        $this->assertFalse($service->delete($account));
        $this->assertNotNull($account->fresh());
        $this->assertSame(1, $installment->payments()->count());
        $this->assertNull($service->updateInstallmentPayment($payment, ['amount' => 1100]));
        $this->assertSame(300.0, $payment->fresh()->amount);
        $this->assertSame(300.0, CashMovement::query()->sole()->amount);
    }

    #[DataProvider('domains')]
    public function test_cash_movement_failure_rolls_back_payment_and_status(string $domain): void
    {
        [$service, $account, $installment] = $this->account($domain);
        $cash = Mockery::mock(CashMovementService::class)->makePartial();
        $method = $domain === 'receivable' ? 'syncForReceivablePayment' : 'syncForPayablePayment';
        $cash->shouldReceive($method)->once()->andReturnUsing(function () use ($cash) {
            $cash->setError('Falha simulada no movimento', ['movement' => ['Falha simulada']]);

            return null;
        });
        $service = new ($service::class)(cashMovementService: $cash);
        $this->assertNull($service->registerInstallmentPayment($installment, 1000, '2026-10-16', [
            'financial_account_id' => $this->bank->id,
        ]));
        $this->assertSame('Falha simulada no movimento', $service->getMessage());
        $this->assertSame(0, $installment->payments()->count());
        $this->assertSame(1000.0, $installment->fresh()->balance_amount);
        $this->assertFalse($account->fresh()->paid);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    #[DataProvider('domains')]
    public function test_status_failure_restores_deleted_payment_and_movement(string $domain): void
    {
        [$service, $account, $installment] = $this->account($domain);
        $payment = $service->registerInstallmentPayment($installment, 300, '2026-10-16', ['financial_account_id' => $this->bank->id]);
        $this->assertNotNull($payment);
        $account::saving(function ($record): void {
            if ((float) $record->paid_amount === 0.0) {
                throw new \RuntimeException('Falha simulada na sincronização');
            }
        });
        $this->assertFalse($service->deleteInstallmentPayment($payment));
        $this->assertNotNull($payment->fresh());
        $this->assertSame(700.0, $installment->fresh()->balance_amount);
        $this->assertSame(300.0, CashMovement::query()->sole()->amount);
    }

    #[DataProvider('domains')]
    public function test_derived_movement_cannot_be_edited_manually(string $domain): void
    {
        [$service, , $installment] = $this->account($domain);
        $this->assertNotNull($service->registerInstallmentPayment($installment, 300, '2026-10-16', ['financial_account_id' => $this->bank->id]));
        $movement = CashMovement::query()->sole();
        $cash = app(CashMovementService::class);
        $this->assertNull($cash->updateManual($movement, ['amount' => 500]));
        $this->assertSame(300.0, $movement->fresh()->amount);
    }

    public function test_cancelled_payable_has_no_automatic_payment_and_remains_cancelled_after_sync(): void
    {
        [$service, $account, $installment] = $this->account('payable');
        $account->update(['auto_register_payment_on_due_date' => true, 'auto_payment_financial_account_id' => $this->bank->id]);
        $this->assertTrue($service->cancel($account, 'Cancelamento de teste'));
        $this->assertSame('cancelled', $installment->fresh()->status->value);
        $this->artisan('account-payables:process-auto-payments', ['--date' => '2026-10-16'])->assertSuccessful();
        $this->assertSame(0, $installment->payments()->count());
        $this->assertNull($service->registerInstallmentPayment($installment, 1000, '2026-10-16'));
        (new SyncAccountPayableStatusFromInstallmentsAction($account))->execute();
        $this->assertSame('cancelled', $account->fresh()->status->value);
    }

    #[DataProvider('domains')]
    public function test_direct_model_deletion_also_preserves_payment_history(string $domain): void
    {
        [$service, $account, $installment] = $this->account($domain);
        $this->assertNotNull($service->registerInstallmentPayment($installment, 300, '2026-10-16', ['financial_account_id' => $this->bank->id]));
        foreach ([$account, $installment] as $record) {
            try {
                $record->delete();
                $this->fail('A exclusão direta deveria ser bloqueada.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('payments', $exception->errors());
            }
            $this->assertNotNull($record->fresh());
        }
        $this->assertSame(1, $installment->payments()->count());
        $this->assertDatabaseCount('cash_movements', 1);
    }

    public function test_integrity_audit_reports_inconsistency_without_changing_records(): void
    {
        [$service, $account, $installment] = $this->account('receivable');
        $this->assertNotNull($service->registerInstallmentPayment($installment, 300, '2026-10-16', ['financial_account_id' => $this->bank->id]));
        $this->artisan('financial:audit-integrity', ['--company' => $account->company_id])->assertSuccessful();
        $installment->update(['balance_amount' => 999]);
        $before = $installment->fresh()->getRawOriginal();
        $this->artisan('financial:audit-integrity', ['--company' => $account->company_id])
            ->expectsOutputToContain('totais_da_parcela_divergentes')->assertFailed();
        $this->assertSame($before, $installment->fresh()->getRawOriginal());
        $this->artisan('financial:audit-integrity', ['--company' => $account->company_id + 1])->assertSuccessful();
    }

    public function test_partial_payment_prevents_cancellation_without_explicit_reversal(): void
    {
        [$service, $account, $installment] = $this->account('payable');
        $this->assertNotNull($service->registerInstallmentPayment($installment, 300, '2026-10-16', ['financial_account_id' => $this->bank->id]), $service->getMessageUser());
        $this->assertFalse($service->cancel($account, 'Cancelamento de teste'));
        $this->assertSame('partially_paid', $account->fresh()->status->value);
        $this->assertSame(700.0, $installment->fresh()->balance_amount);
    }
}
