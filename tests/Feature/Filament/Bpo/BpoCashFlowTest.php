<?php

namespace Tests\Feature\Filament\Bpo;

use App\Filament\Bpo\Pages\CashFlow;
use App\Models\AccountPayable;
use App\Models\AccountReceivable;
use App\Models\CashMovement;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\User;
use App\Services\Access\CompanyTeamService;
use App\Services\Bpo\BpoCashFlowService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BpoCashFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $bpo;

    private Company $company;

    private FinancialAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 28)->setTime(12, 0));
        $this->owner = User::factory()->create();
        $this->bpo = User::factory()->create();
        $this->company = $this->authorizeCompany('Empresa principal');
        $this->bank = $this->account(['company_id' => $this->company->id, 'name' => 'Banco principal',
            'type' => 'bank', 'opening_balance' => 1000, 'opened_at' => '2026-01-01', 'is_active' => true]);
        $this->actingAs($this->bpo);
        Filament::setCurrentPanel(Filament::getPanel('bpo'));
        Filament::setTenant(null);
    }

    private function account(array $attributes): FinancialAccount
    {
        return FinancialAccount::withoutEvents(fn () => FinancialAccount::create($attributes));
    }

    private function authorizeCompany(string $name, ?array $permissions = null): Company
    {
        $company = Company::factory()->create(['name' => $name]);
        $this->owner->companies()->attach($company, ['role' => 'owner', 'is_active' => true]);
        $teams = app(CompanyTeamService::class);
        $role = $teams->saveRole($this->owner, $company, ['name' => 'Analista de caixa', 'panels' => ['bpo'],
            'permissions' => $permissions ?? ['financial_accounts.view' => 'company', 'cash_movements.view' => 'company',
                'account_payables.view' => 'company', 'account_receivables.view' => 'company']]);
        $teams->authorizeBpo($this->owner, $company, $this->bpo, [$role->id], true, []);

        return $company;
    }

    private function title(string $type, string $date, float $amount, ?Company $company = null, array $extra = []): mixed
    {
        $company ??= $this->company;
        $model = $type === 'payable' ? AccountPayable::class : AccountReceivable::class;
        $parent = $model::withoutEvents(fn () => $model::create(['company_id' => $company->id, 'created_by' => $this->bpo->id,
            ...($type === 'payable' ? ['sequence_number' => (string) ($model::withoutGlobalScopes()->count() + 1)] : []),
            'manual_counterparty_name' => 'Parceiro de teste', 'status' => 'pending', 'due_date' => $date,
            'due_amount' => $amount, 'paid_amount' => 0, 'description' => 'Título de teste']));
        $paidColumn = $type === 'payable' ? 'paid_amount' : 'received_amount';

        return $model::withoutEvents(fn () => $parent->installments()->create([...['company_id' => $company->id, 'sequence_number' => '01',
            'status' => 'pending', 'due_date' => $date, 'original_amount' => $amount, 'due_amount' => $amount,
            $paidColumn => 0, 'balance_amount' => $amount], ...$extra]));
    }

    private function movement(string $date, string $direction, float $amount, ?FinancialAccount $account = null, array $extra = []): CashMovement
    {
        $account ??= $this->bank;

        return CashMovement::withoutEvents(fn () => CashMovement::create([...['company_id' => $account->company_id, 'financial_account_id' => $account->id,
            'transaction_date' => $date, 'direction' => $direction, 'amount' => $amount,
            'origin_type' => 'manual', 'description' => 'Movimento de teste', 'created_by' => $this->bpo->id], ...$extra]));
    }

    private function report(string $start = '2026-10-28', string $end = '2026-11-03', ?array $ids = null): array
    {
        return app(BpoCashFlowService::class)->report($this->bpo, $ids ?? [$this->company->id], $start, $end);
    }

    public function test_historical_closing_uses_the_previous_day_and_reversals_on_their_dates(): void
    {
        $original = $this->movement('2026-10-25', 'outflow', 200);
        $this->movement('2026-10-27', 'inflow', 200, extra: ['reversal_of_id' => $original->id]);
        $this->movement('2026-10-28', 'outflow', 100);
        $this->movement('2026-11-04', 'inflow', 9000);
        $report = $this->report();
        $this->assertSame(100000, $report['opening']);
        $this->assertSame(90000, $report['closing']);
        $this->assertCount(7, $report['days']);
        $past = $this->report('2026-10-25', '2026-10-25');
        $this->assertSame(100000, $past['opening']);
        $this->assertSame(80000, $past['closing']);
        $this->assertCount(1, $past['days']);
    }

    public function test_overdue_payables_partial_payments_and_ignored_titles_are_handled_without_double_counting(): void
    {
        $this->title('payable', '2026-10-26', 300);
        $this->title('receivable', '2026-10-26', 800);
        $this->title('receivable', '2026-10-29', 100);
        $this->title('payable', '2026-10-28', 400, extra: ['paid_amount' => 150, 'balance_amount' => 250]);
        $this->movement('2026-10-28', 'outflow', 150);
        $ignored = $this->title('payable', '2026-10-28', 5000);
        $ignored->forceFill(['ignored_at' => now()])->saveQuietly();
        $this->title('payable', '2026-10-28', 5000, extra: ['status' => 'cancelled']);
        $report = $this->report();
        $this->assertSame(70000, $report['days']['2026-10-28']['out']);
        $this->assertSame(10000, $report['in']);
        $this->assertSame(80000, $report['overdue_receivables']);
        $this->assertSame(40000, $report['closing']);
    }

    public function test_future_opening_carries_intervening_commitments_once(): void
    {
        $this->title('payable', '2026-10-27', 100);
        $this->title('payable', '2026-10-29', 200);
        $this->title('receivable', '2026-10-29', 50);
        $this->title('payable', '2026-10-30', 70);
        $this->movement('2026-10-29', 'inflow', 25);
        $report = $this->report('2026-10-30', '2026-10-30');
        $this->assertSame(77500, $report['opening']);
        $this->assertSame(70500, $report['closing']);
        $this->assertSame(7000, $report['out']);
    }

    public function test_consolidation_does_not_hide_an_individual_company_shortage(): void
    {
        $second = $this->authorizeCompany('Segunda empresa');
        $this->account(['company_id' => $second->id, 'name' => 'Banco 2', 'type' => 'bank', 'opening_balance' => 5000]);
        $this->title('payable', '2026-10-28', 1200);
        $report = $this->report(ids: [$this->company->id, $second->id]);
        $this->assertSame(480000, $report['closing']);
        $this->assertNull($report['first_shortage']);
        $this->assertSame(-20000, $report['rows']->first()['first_shortage']['balance']);
    }

    public function test_transfers_cancel_in_the_company_total_and_new_accounts_enter_on_their_opening_day(): void
    {
        $second = $this->account(['company_id' => $this->company->id, 'name' => 'Outra conta',
            'type' => 'bank', 'opening_balance' => 100, 'opened_at' => '2026-10-29']);
        $this->movement('2026-10-29', 'outflow', 2000, extra: ['transfer_group_id' => 'transfer-test']);
        $this->movement('2026-10-29', 'inflow', 2000, $second, ['transfer_group_id' => 'transfer-test']);
        $report = $this->report();
        $this->assertSame(100000, $report['opening']);
        $this->assertSame(110000, $report['closing']);
        $this->assertSame(10000, $report['days']['2026-10-29']['adjustment']);
        $this->assertSame(0, $report['in']);
        $this->assertSame(0, $report['out']);
        $this->assertSame(110000, $report['rows']->first()['without_receipts']);
    }

    public function test_permissions_scope_titles_and_balances_and_flag_partial_analysis(): void
    {
        $restricted = $this->authorizeCompany('Empresa restrita', ['account_payables.view' => 'own']);
        $this->title('payable', '2026-10-28', 100, $restricted);
        $foreign = $this->title('payable', '2026-10-28', 900, $restricted);
        $foreign->accountPayable->forceFill(['created_by' => $this->owner->id])->saveQuietly();
        $this->account(['company_id' => $restricted->id, 'name' => 'Saldo oculto', 'type' => 'bank', 'opening_balance' => 5000]);
        $report = $this->report(ids: [$restricted->id]);
        $this->assertTrue($report['partial']);
        $this->assertSame(0, $report['opening']);
        $this->assertSame(10000, $report['out']);
        $this->assertCount(1, $report['titles']);
        $this->assertCount(0, $report['accounts']);
    }

    public function test_page_renders_filters_chart_and_rejects_forged_companies_and_revoked_access(): void
    {
        $this->title('payable', '2026-10-28', 100);
        $this->get(route('filament.bpo.cash-flow'))->assertOk()->assertSee('Fluxo de caixa e previsão')
            ->assertSee('bpo-shell', false)->assertSee('Evolução do saldo diário')->assertSee('Parceiro de teste')
            ->assertSee('.bpo-month-control { display: inline-grid', false)
            ->assertSee('.bpo-company-dialog-body { display: flex', false);
        $page = Livewire::test(CashFlow::class)->assertSet('startDate', '2026-10-28')->assertSet('endDate', '2026-11-03');
        $page->call('setPeriod', 'next_30_days')->assertSet('endDate', '2026-11-26');
        $page->set('endDate', '2026-10-01')->assertSee('A data final deve ser igual ou posterior');
        $other = Company::factory()->create();
        Livewire::test(CashFlow::class)->set('companyIds', [$other->id])->assertForbidden();
        $mounted = Livewire::test(CashFlow::class);
        $this->bpo->companies()->updateExistingPivot($this->company->id, ['is_active' => false]);
        $mounted->call('$refresh')->assertForbidden();
    }

    public function test_company_can_be_unchecked_through_the_http_livewire_endpoint_with_csrf_validation(): void
    {
        $this->authorizeCompany('Empresa para desmarcar');
        $response = $this->get(route('filament.bpo.cash-flow'))->assertOk();
        preg_match('/wire:snapshot="([^"]+)"/', $response->getContent(), $snapshotMatch);
        preg_match('/<meta name="csrf-token" content="([^"]+)"/', $response->getContent(), $tokenMatch);
        $snapshot = html_entity_decode($snapshotMatch[1], ENT_QUOTES);
        $cookie = $response->getCookie(config('session.cookie'), false);
        $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());

        // Unlike Livewire::test(), exercise the real route, session, CSRF and persistent middleware.
        $this->app['env'] = 'local';
        try {
            $update = $this->postJson('/livewire/update', [
                '_token' => $tokenMatch[1],
                'components' => [['snapshot' => $snapshot, 'updates' => ['companyIds' => [(string) $this->company->id]], 'calls' => []]],
            ], ['X-Livewire' => '']);
        } finally {
            $this->app['env'] = 'testing';
        }

        $update->assertOk();
        $data = json_decode($update->json('components.0.snapshot'), true);
        $this->assertSame([(string) $this->company->id], $data['data']['companyIds'][0]);
    }

    public function test_month_navigation_and_custom_range_keep_the_calendar_and_report_in_sync(): void
    {
        $page = Livewire::test(CashFlow::class)
            ->assertSet('rangeData.date_range', '28/10/2026 - 03/11/2026')
            ->call('setPeriod', 'month')
            ->assertSet('startDate', '2026-10-01')->assertSet('endDate', '2026-10-31')
            ->call('moveMonth', 1)
            ->assertSet('month', '2026-11')->assertSet('endDate', '2026-11-30')
            ->assertSet('rangeData.date_range', '01/11/2026 - 30/11/2026')
            ->set('month', '2026-12')->call('moveMonth', 1)
            ->assertSet('month', '2027-01')->assertSet('startDate', '2027-01-01')->assertSet('endDate', '2027-01-31')
            ->call('moveMonth', -1)->assertSet('month', '2026-12')
            ->set('month', '2028-02')->assertSet('endDate', '2028-02-29')
            ->call('selectMonth', '2026-04')->assertSet('startDate', '2026-04-01')->assertSet('endDate', '2026-04-30');

        $page->set('rangeData.date_range', '05/10/2026 - 20/10/2026')
            ->assertSet('period', 'custom')->assertSet('startDate', '2026-10-05')->assertSet('endDate', '2026-10-20')
            ->call('setPeriod', 'next_7_days')
            ->assertSet('rangeData.date_range', '28/10/2026 - 03/11/2026');
    }

    public function test_company_modal_applies_only_confirmed_selection_and_rejects_companies_outside_the_portfolio(): void
    {
        $second = $this->authorizeCompany('Segunda empresa no modal');
        $allIds = [(string) $this->company->id, (string) $second->id];
        $page = Livewire::test(CashFlow::class)
            ->assertSet('companyIds', $allIds)
            ->call('openCompanies')->assertDispatched('open-modal', id: 'cash-flow-companies')
            ->assertSet('draftCompanyIds', $allIds)
            ->set('draftCompanyIds', [(string) $second->id])
            ->assertSet('companyIds', $allIds)
            ->call('applyCompanies')->assertSet('companyIds', [(string) $second->id])
            ->assertDispatched('close-modal', id: 'cash-flow-companies');

        $page->call('openCompanies')->set('draftCompanyIds', [])->call('openCompanies')
            ->assertSet('draftCompanyIds', [(string) $second->id])
            ->call('selectAllCompanies')->assertSet('draftCompanyIds', $allIds)
            ->assertSet('companyIds', [(string) $second->id])
            ->set('draftCompanyIds', [])->call('applyCompanies')->assertSet('companyIds', [])
            ->assertSee('Selecione uma ou mais empresas');

        $foreign = Company::factory()->create();
        $page->set('draftCompanyIds', [$foreign->id])->call('applyCompanies')->assertForbidden();
    }
}
