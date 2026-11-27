<?php

namespace Tests\Feature\Filament;

use App\Filament\Bpo\Resources\AccountReceivableInstallments\Pages\ListInstallments as ListBpoReceivableInstallments;
use App\Filament\Clusters\Financial\Resources\AccountReceivableInstallments\Pages\ListAccountReceivableInstallments;
use App\Models\AccountReceivableInstallmentPayment;
use App\Models\CashMovement;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\User;
use App\Services\Access\CompanyTeamService;
use App\Services\Access\PermissionCatalog;
use App\Services\AccountReceivable\AccountReceivableService;
use App\Services\AccountReceivable\BulkRegisterReceivablePaymentsService;
use App\Support\Financial\Money;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class BulkReceivablePaymentsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private FinancialAccount $bank;

    private FinancialCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->company = Company::factory()->create();
        $this->user->companies()->attach($this->company, ['role' => 'member', 'is_active' => true]);
        $this->bank = FinancialAccount::create([
            'company_id' => $this->company->id,
            'name' => 'Caixa do lote',
            'type' => 'cash',
            'is_active' => true,
            'created_by' => $this->user->id,
        ]);
        $this->category = FinancialCategory::create([
            'company_id' => $this->company->id,
            'name' => 'Recebimentos em lote',
            'allow_receivable' => true,
            'allow_cash_movement' => true,
            'is_active' => true,
            'created_by' => $this->user->id,
        ]);
    }

    private function account(): array
    {
        $service = app(AccountReceivableService::class);
        $account = $service->create([
            'company_id' => $this->company->id,
            'manual_counterparty_name' => 'Cliente do lote',
            'description' => 'Conta para quitação em massa',
            'due_date' => '2026-01-10',
            'due_amount' => 180,
            'installment_count' => 2,
            'financial_category_id' => $this->category->id,
        ], $this->user->id);
        $this->assertNotNull($account, $service->getMessageUser());

        $installments = $account->installments()->orderBy('id')->get();
        $installments[0]->update([
            'due_date' => '2026-01-10', 'original_amount' => 100, 'due_amount' => 100, 'balance_amount' => 100,
        ]);
        $installments[1]->update([
            'due_date' => '2026-02-10', 'original_amount' => 80, 'due_amount' => 80, 'balance_amount' => 80,
        ]);

        return [$account, $service, $installments->fresh()];
    }

    private function authenticate(string $panel = 'admin'): void
    {
        $this->actingAs($this->user);
        Filament::setCurrentPanel(Filament::getPanel($panel));
        Filament::setTenant($this->company);
    }

    private function authorizeBpo(array $permissions): void
    {
        $teams = app(CompanyTeamService::class);
        $owner = User::factory()->create();
        $owner->companies()->attach($this->company, ['role' => 'owner', 'is_active' => true]);
        $role = $teams->saveRole($owner, $this->company, [
            'name' => 'Financeiro BPO', 'permissions' => $permissions, 'panels' => ['bpo'],
        ]);
        $teams->authorizeBpo($owner, $this->company, $this->user, [$role->id], false, []);
    }

    public function test_finance_bulk_receipt_uses_each_due_date_by_default_and_settles_the_remaining_balance(): void
    {
        [, $service, $installments] = $this->account();
        $partial = $service->registerInstallmentPayment($installments[0], 30, '2026-01-05', [
            'financial_account_id' => $this->bank->id,
        ]);
        $this->assertNotNull($partial, $service->getMessageUser());
        $this->authenticate();

        $page = Livewire::test(ListAccountReceivableInstallments::class)
            ->selectTableRecords($installments->modelKeys())
            ->mountAction(TestAction::make('register_bulk_receivable_payments')->table()->bulk());
        $page->assertSet('mountedActions.0.data.use_due_date', true);
        $this->assertStringContainsString('Usar Dt. Vencimento como Dt de Recebimento?', $page->getMountedActionModalHtml());
        $page->setActionData([
            'use_due_date' => true,
            'financial_account_id' => $this->bank->id,
        ])->callMountedAction()->assertHasNoActionErrors();

        $payments = AccountReceivableInstallmentPayment::query()->orderBy('id')->get();
        $this->assertCount(3, $payments);
        $bulk = $payments->where('id', '!=', $partial->id)->values();
        $this->assertSame('2026-01-10', $bulk[0]->payment_date->toDateString());
        $this->assertSame(7000, Money::stored($bulk[0]));
        $this->assertSame('2026-02-10', $bulk[1]->payment_date->toDateString());
        $this->assertSame(8000, Money::stored($bulk[1]));
        foreach ($payments as $payment) {
            $this->assertSame(0, Money::stored($payment, 'interest_amount'));
            $this->assertSame(0, Money::stored($payment, 'fine_amount'));
            $this->assertSame(0, Money::stored($payment, 'discount_amount'));
        }
        $this->assertSame(100.0, (float) $installments[0]->fresh()->received_amount);
        $this->assertSame(0.0, (float) $installments[0]->fresh()->balance_amount);
        $this->assertSame(80.0, (float) $installments[1]->fresh()->received_amount);
        $this->assertSame(0.0, (float) $installments[1]->fresh()->balance_amount);
        $this->assertSame(3, CashMovement::query()->count());
    }

    public function test_finance_bulk_receipt_uses_the_informed_date_for_every_installment_when_toggle_is_off(): void
    {
        [, , $installments] = $this->account();
        $this->authenticate();

        Livewire::test(ListAccountReceivableInstallments::class)
            ->selectTableRecords($installments->modelKeys())
            ->callAction(TestAction::make('register_bulk_receivable_payments')->table()->bulk(), data: [
                'use_due_date' => false,
                'payment_date' => '2026-11-20',
                'financial_account_id' => $this->bank->id,
            ])->assertHasNoActionErrors();

        $payments = AccountReceivableInstallmentPayment::query()->orderBy('id')->get();
        $this->assertCount(2, $payments);
        foreach ($payments as $payment) {
            $this->assertSame('2026-11-20', $payment->payment_date->toDateString());
            $this->assertSame($payment->payment_date->toDateString(), $payment->installment->competence_date->toDateString());
        }
    }

    public function test_bpo_bulk_receipt_is_available_only_with_settle_permission_and_uses_due_dates(): void
    {
        [, , $installments] = $this->account();
        $this->authorizeBpo(PermissionCatalog::templates()['Financeiro']);
        $this->authenticate('bpo');

        Livewire::test(ListBpoReceivableInstallments::class)
            ->selectTableRecords($installments->modelKeys())
            ->callAction(TestAction::make('register_bulk_receivable_payments')->table()->bulk(), data: [
                'use_due_date' => true,
                'financial_account_id' => $this->bank->id,
            ])->assertHasNoActionErrors();

        $this->assertSame(
            ['2026-01-10', '2026-02-10'],
            AccountReceivableInstallmentPayment::query()->orderBy('id')->pluck('payment_date')->map(fn ($date) => $date->toDateString())->all(),
        );
    }

    public function test_bpo_bulk_receipt_is_hidden_without_settle_permission(): void
    {
        $this->authorizeBpo(['account_receivables.view' => 'company']);
        $this->authenticate('bpo');

        Livewire::test(ListBpoReceivableInstallments::class)
            ->assertActionHidden(TestAction::make('register_bulk_receivable_payments')->table()->bulk());
    }

    public function test_bulk_receipt_rolls_back_all_payments_when_a_later_installment_is_invalid(): void
    {
        [, , $installments] = $this->account();
        $installments[1]->update(['financial_category_id' => null, 'chart_account_id' => null]);
        $this->authenticate();

        try {
            app(BulkRegisterReceivablePaymentsService::class)->register(
                $installments->fresh(),
                $this->company,
                $this->user,
                ['use_due_date' => true, 'financial_account_id' => $this->bank->id],
                'admin',
            );
            $this->fail('A quitação em lote deveria ter falhado.');
        } catch (ValidationException) {
        }

        $this->assertDatabaseCount('account_receivable_installment_payments', 0);
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertSame(100.0, (float) $installments[0]->fresh()->balance_amount);
        $this->assertSame(80.0, (float) $installments[1]->fresh()->balance_amount);
    }
}
