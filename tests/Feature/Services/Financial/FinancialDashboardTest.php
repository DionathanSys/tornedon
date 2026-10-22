<?php

namespace Tests\Feature\Services\Financial;

use App\Filament\Pages\FinancialDashboard;
use App\Models\AccountPayable;
use App\Models\AccountReceivable;
use App\Models\Company;
use App\Models\User;
use App\Services\AccountPayable\Actions\Installment\SyncAccountPayableStatusFromInstallmentsAction;
use App\Services\AccountReceivable\Actions\Installment\SyncAccountReceivableStatusFromInstallmentsAction;
use App\Services\Financial\FinancialDashboardService;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FinancialDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 22)->setTime(15, 30));
    }

    public function test_period_boundaries_and_overdue_do_not_include_today(): void
    {
        $company = Company::factory()->create();
        $service = app(FinancialDashboardService::class);

        foreach (['receivable', 'payable'] as $type) {
            $rows = [];
            foreach ([-1, 0, 1, 6, 7, 29, 30] as $offset) {
                $rows[$offset] = $this->installment($company, $type, today()->addDays($offset)->toDateString());
            }
            foreach (['today' => [0], 'tomorrow' => [1], 'next_7_days' => [0, 1, 6], 'next_30_days' => [0, 1, 6, 7, 29], 'overdue' => [-1]] as $period => $offsets) {
                $this->assertEqualsCanonicalizing(
                    array_map(fn ($offset) => $rows[$offset]->id, $offsets),
                    $service->query($company->id, $type, $period)->pluck('id')->all(),
                );
            }
        }
    }

    public function test_totals_use_partial_balance_and_exclude_settled_cancelled_and_other_companies(): void
    {
        $company = Company::factory()->create();
        $other = Company::factory()->create();
        $service = app(FinancialDashboardService::class);

        foreach (['receivable' => 'received', 'payable' => 'paid'] as $type => $settled) {
            $this->installment($company, $type, '2026-10-22', 'partially_'.$settled, balance: 37.55);
            $this->installment($company, $type, '2026-10-22', 'overdue', balance: 10);
            $this->installment($company, $type, '2026-10-22', 'pending', balance: 0);
            $this->installment($company, $type, '2026-10-22', $settled);
            $this->installment($company, $type, '2026-10-22', 'cancelled');
            $this->installment($company, $type, '2026-10-22', parentStatus: 'cancelled');
            $this->installment($company, $type, '2026-10-22', parentStatus: $settled);
            $this->installment($other, $type, '2026-10-22');
            $mismatched = $this->installment($other, $type, '2026-10-22');
            $mismatched->update(['company_id' => $company->id]);

            $this->assertSame(47.55, $service->total($company->id, $type, 'today'));
            $this->assertSame(0.0, $service->total($company->id, $type, 'overdue'));
            $this->installment($company, $type, '2026-10-21', 'partially_'.$settled, balance: 12.34);
            $this->assertSame(12.34, $service->total($company->id, $type, 'overdue'));
        }
    }

    public function test_status_sync_only_marks_dates_before_today_as_overdue_and_preserves_partial_status(): void
    {
        $company = Company::factory()->create();
        foreach (['receivable' => SyncAccountReceivableStatusFromInstallmentsAction::class, 'payable' => SyncAccountPayableStatusFromInstallmentsAction::class] as $type => $action) {
            $row = $this->installment($company, $type, '2026-10-22');
            $account = $type === 'receivable' ? $row->accountReceivable : $row->accountPayable;
            $this->assertSame('pending', (new $action($account))->execute()?->status->value);

            $row->update(['due_date' => '2026-10-21']);
            $this->assertSame('overdue', (new $action($account))->execute()?->status->value);

            $settled = $type === 'receivable' ? 'received' : 'paid';
            $row->update([$settled.'_amount' => 20]);
            $this->assertSame('partially_'.$settled, (new $action($account))->execute()?->status->value);
        }
    }

    public function test_page_renders_filters_search_and_independent_pagination(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create();
        $user->companies()->attach($company, ['role' => 'owner', 'is_active' => true]);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($company);
        $this->assertNull(FinancialDashboard::getCluster());
        $this->assertSame('Financeiro', FinancialDashboard::getNavigationGroup());
        $this->assertSame('Dashboard Financeiro', FinancialDashboard::getNavigationLabel());
        $this->assertStringEndsWith('/admin/'.$company->getRouteKey().'/financial-dashboard', FinancialDashboard::getUrl());
        for ($i = 0; $i < 11; $i++) {
            $this->installment($company, 'receivable', '2026-10-22');
        }
        $this->installment($company, 'payable', '2026-10-22', 'overdue');
        $this->installment($company, 'receivable', '2026-10-21');

        Livewire::test(FinancialDashboard::class)
            ->assertSuccessful()
            ->assertSee('Contas a receber')
            ->assertSee('Contas a pagar')
            ->assertSee('Vence hoje')
            ->assertDontSee('Vencido há')
            ->call('setPage', 2, 'receivablePage')
            ->assertSet('paginators.receivablePage', 2)
            ->set('search', 'nenhum resultado')
            ->assertSet('paginators.receivablePage', 1)
            ->assertSee('Nenhuma parcela em aberto')
            ->set('search', '')
            ->set('period', 'overdue')
            ->assertSee('Vencido há 1 dia(s)');
    }

    public function test_page_does_not_disclose_a_module_without_permission(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create();
        $user->companies()->attach($company, ['role' => 'member', 'is_active' => true]);
        $user->companyAccesses()->create([
            'company_id' => $company->id, 'permissions' => ['account_receivables.access'],
            'panels' => ['admin' => true], 'navigation' => [],
        ]);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($company);

        Livewire::test(FinancialDashboard::class)
            ->assertSuccessful()
            ->assertSee('Contas a receber')
            ->assertDontSee('Contas a pagar')
            ->assertDontSee('Saldo previsto');

        $user->companyAccesses()->update(['permissions' => json_encode([])]);
        Livewire::test(FinancialDashboard::class)->assertForbidden();
    }

    private function installment(Company $company, string $type, string $date, string $status = 'pending', float $balance = 100, string $parentStatus = 'pending'): Model
    {
        $model = $type === 'receivable' ? AccountReceivable::class : AccountPayable::class;
        $account = $model::create([
            'company_id' => $company->id, 'status' => $parentStatus,
            'due_date' => $date, 'due_amount' => 100, 'paid_amount' => 0,
            'manual_counterparty_name' => 'Contraparte teste',
            ...($type === 'payable' ? ['sequence_number' => '01'] : []),
        ]);

        return $account->installments()->create([
            'company_id' => $company->id, 'status' => $status, 'sequence_number' => '01',
            'due_date' => $date, 'original_amount' => 100, 'due_amount' => 100,
            'balance_amount' => $balance,
        ]);
    }
}
