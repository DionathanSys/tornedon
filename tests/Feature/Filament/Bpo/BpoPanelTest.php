<?php

namespace Tests\Feature\Filament\Bpo;

use App\Filament\Bpo\Pages\CompanyOverview;
use App\Filament\Bpo\Pages\CompanyTeam;
use App\Filament\Bpo\Pages\DreReport;
use App\Filament\Bpo\Pages\FiscalDocuments;
use App\Filament\Bpo\Pages\Portfolio;
use App\Filament\Bpo\Resources\AccountPayableResource;
use App\Filament\Bpo\Resources\AccountPayables\Pages\CreateAccount;
use App\Filament\Bpo\Resources\AccountPayables\Pages\EditAccount;
use App\Filament\Bpo\Resources\AccountReceivableResource;
use App\Filament\Bpo\Resources\BankStatementImportResource;
use App\Filament\Bpo\Resources\BankStatementImports\LinesRelationManager;
use App\Filament\Bpo\Resources\BankStatementImports\Pages\ViewImport;
use App\Filament\Bpo\Resources\CashMovementResource;
use App\Http\Responses\LoginResponse;
use App\Models\AccountPayable;
use App\Models\BankStatementImport;
use App\Models\BpoEngagement;
use App\Models\Company;
use App\Models\CompanyRole;
use App\Models\CompanyRoleAssignment;
use App\Models\FinancialAccount;
use App\Models\User;
use App\Services\Access\CompanyAuthorization;
use App\Services\Access\CompanyTeamService;
use App\Services\Access\PermissionCatalog;
use App\Services\Access\UserAccessService;
use App\Services\Bpo\BpoAccessService;
use App\Services\Bpo\BpoPortfolioService;
use Filament\Actions\Testing\TestAction;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BpoPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $bpo;

    private Company $company;

    private BpoEngagement $engagement;

    private CompanyTeamService $teams;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->bpo = User::factory()->create();
        $this->company = Company::factory()->create(['name' => 'Empresa da carteira']);
        $this->owner->companies()->attach($this->company, ['role' => 'owner', 'is_active' => true]);
        $this->teams = app(CompanyTeamService::class);
        $role = $this->role('Financeiro BPO', PermissionCatalog::templates()['Financeiro'] + ['fiscal_documents.view' => 'company']);
        $this->engagement = $this->teams->authorizeBpo($this->owner, $this->company, $this->bpo, [$role->id], true, ['account_payables.view' => 'company']);
    }

    private function role(string $name, array $permissions, array $panels = ['admin', 'operation', 'mobile', 'bpo']): CompanyRole
    {
        return $this->teams->saveRole($this->owner, $this->company, ['name' => $name, 'permissions' => $permissions, 'panels' => $panels]);
    }

    private function authenticate(?User $user = null, ?Company $company = null): void
    {
        $this->actingAs($user ?? $this->bpo);
        Filament::setCurrentPanel(Filament::getPanel('bpo'));
        Filament::setTenant($company);
    }

    private function payable(User $creator, string $label, int $amount = 100): AccountPayable
    {
        $account = AccountPayable::create(['company_id' => $this->company->id, 'created_by' => $creator->id, 'manual_counterparty_name' => $label,
            'sequence_number' => '01', 'status' => 'pending', 'due_date' => today()->subDay(), 'due_amount' => $amount, 'paid_amount' => 0]);
        $account->installments()->create(['company_id' => $this->company->id, 'sequence_number' => '01', 'status' => 'pending',
            'due_date' => today()->subDay(), 'original_amount' => $amount, 'due_amount' => $amount, 'paid_amount' => 0, 'balance_amount' => $amount]);

        return $account;
    }

    public function test_authorized_bpo_gets_a_custom_desktop_portfolio_and_tenant_workspace(): void
    {
        $this->authenticate();
        $this->get('/')->assertRedirect(route('filament.bpo.portfolio'));
        $this->get('/bpo')->assertRedirect(route('filament.bpo.portfolio'));
        $this->get(route('filament.bpo.portfolio'))->assertOk()->assertSee('bpo-shell', false)->assertSee('Seu trabalho começa por aqui.')
            ->assertSee('Empresa da carteira')->assertDontSee('operation-ui', false);
        $this->get(CompanyOverview::getUrl(panel: 'bpo', tenant: $this->company))->assertOk()->assertSee('Área de trabalho')->assertSee('Empresa da carteira');
    }

    public function test_a_panel_flag_or_role_name_alone_never_makes_a_company_part_of_the_bpo_portfolio(): void
    {
        $user = User::factory()->create();
        $role = $this->role('BPO sem atendimento', ['account_payables.view' => 'company']);
        $this->teams->assign($this->owner, $this->company, $user, [$role->id]);
        $this->assertFalse($user->canAccessPanel(Filament::getPanel('bpo')));
        $this->authenticate($user);
        $this->get(route('filament.bpo.portfolio'))->assertForbidden();
    }

    public function test_portfolio_does_not_include_own_companies_or_other_bpos_clients(): void
    {
        $own = Company::factory()->create(['name' => 'Empresa própria fora da carteira']);
        $other = Company::factory()->create(['name' => 'Cliente de outro BPO']);
        $this->bpo->companies()->attach($own, ['role' => 'owner', 'is_active' => true]);
        $this->authenticate();
        $this->get(route('filament.bpo.portfolio'))->assertOk()->assertDontSee($own->name)->assertDontSee($other->name);
        $this->get(CompanyOverview::getUrl(panel: 'bpo', tenant: $own))->assertForbidden();
        $this->get(CompanyOverview::getUrl(panel: 'bpo', tenant: $other))->assertNotFound();
    }

    public function test_auxiliary_and_summary_respect_own_records_even_without_a_session_actor(): void
    {
        $assistant = User::factory()->create();
        $role = $this->role('Auxiliar restrito', ['account_payables.view' => 'own']);
        $this->teams->assign($this->bpo, $this->company, $assistant, [$role->id], $this->engagement->id);
        $own = $this->payable($assistant, 'Registro permitido');
        $foreign = $this->payable($this->bpo, 'Registro de outra pessoa', 900);
        $summary = app(BpoPortfolioService::class)->summary($assistant, $this->company);
        $this->assertSame(1, $summary['payable_due']);
        $this->assertSame(100.0, $summary['payable_balance']);
        $this->assertNull($summary['receivable_balance']);
        $this->assertNull($summary['reconciliation_pending']);
        $this->authenticate($assistant, $this->company);
        $this->get(AccountPayableResource::getUrl(panel: 'bpo', tenant: $this->company))->assertOk()->assertSee('Registro permitido')->assertDontSee('Registro de outra pessoa');
        $this->get(AccountPayableResource::getUrl('view', ['record' => $foreign], panel: 'bpo', tenant: $this->company))->assertNotFound();
        $this->get(AccountPayableResource::getUrl('create', panel: 'bpo', tenant: $this->company))->assertForbidden();
    }

    public function test_financial_pages_render_inside_the_custom_shell_and_scoped_routes(): void
    {
        $record = $this->payable($this->bpo, 'Fornecedor da empresa');
        $this->authenticate(company: $this->company);
        foreach ([AccountPayableResource::class, AccountReceivableResource::class, CashMovementResource::class, BankStatementImportResource::class] as $resource) {
            $this->get($resource::getUrl(panel: 'bpo', tenant: $this->company))->assertOk()->assertSee('bpo-shell', false)
                ->assertSee('bpo-list-workbench', false)->assertSee('bpo-workbench-page-heading', false)->assertDontSee('operation-ui', false);
        }
        foreach ([AccountPayableResource::class, AccountReceivableResource::class, CashMovementResource::class] as $resource) {
            $this->get($resource::getUrl('create', panel: 'bpo', tenant: $this->company))->assertOk()->assertSee('Confira antes de salvar');
        }
        $this->get(AccountPayableResource::getUrl('edit', ['record' => $record], panel: 'bpo', tenant: $this->company))->assertOk();
        $this->get(AccountPayableResource::getUrl('view', ['record' => $record], panel: 'bpo', tenant: $this->company))->assertOk();
        foreach ([FiscalDocuments::class, DreReport::class, CompanyTeam::class] as $page) {
            $this->get($page::getUrl(panel: 'bpo', tenant: $this->company))->assertOk()->assertSee('bpo-workbench-page-heading', false);
        }
    }

    public function test_ending_an_engagement_blocks_already_mounted_portfolio_and_workspace(): void
    {
        $this->authenticate();
        $page = Livewire::test(Portfolio::class);
        $this->teams->endBpo($this->owner, $this->company, $this->engagement->id);
        $page->call('$refresh')->assertForbidden();
        $this->get(CompanyOverview::getUrl(panel: 'bpo', tenant: $this->company))->assertForbidden();
    }

    public function test_user_authenticating_on_mobile_is_sent_to_bpo_and_invitations_are_preserved(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('mobile'));
        Filament::setTenant(null);
        Livewire::test(Login::class)->fillForm(['email' => $this->bpo->email, 'password' => 'password'])->call('authenticate')
            ->assertRedirect(route('filament.bpo.portfolio'));
    }

    public function test_login_response_preserves_invitation_destination_and_explicit_management_login(): void
    {
        $this->authenticate();
        Filament::setCurrentPanel(Filament::getPanel('mobile'));
        $request = request();
        session()->put('url.intended', url('/convites-equipe/test-token'));
        $response = app(LoginResponse::class)->toResponse($request);
        $this->assertSame(url('/convites-equipe/test-token'), $response->getTargetUrl());
        session()->put('url.intended', url('/admin'));
        $response = app(LoginResponse::class)->toResponse($request);
        $this->assertSame(route('filament.bpo.portfolio'), $response->getTargetUrl());
        Filament::setCurrentPanel(Filament::getPanel('management'));
        session()->forget('url.intended');
        $this->assertSame(Filament::getPanel('management')->getUrl(), app(LoginResponse::class)->toResponse($request)->getTargetUrl());
    }

    public function test_normal_company_users_keep_their_existing_landing_page(): void
    {
        $user = User::factory()->create();
        $user->companies()->attach($this->company, ['role' => 'member', 'is_active' => true]);
        Filament::setCurrentPanel(Filament::getPanel('mobile'));
        Livewire::test(Login::class)->fillForm(['email' => $user->email, 'password' => 'password'])->call('authenticate')
            ->assertRedirect(Filament::getPanel('mobile')->getUrl());
    }

    public function test_bpo_login_uses_custom_theme_and_shell(): void
    {
        $this->assertTrue(Filament::getPanel('bpo')->hasDarkMode());
        $this->get(Filament::getPanel('bpo')->getLoginUrl())->assertOk()->assertSee('bpo-auth', false)->assertSee('Entre na sua área de trabalho')
            ->assertSee('data-bpo-theme-select', false)->assertSee('Tema do painel');
    }

    public function test_new_payable_is_created_through_the_existing_service_with_server_owned_company(): void
    {
        $this->authenticate(company: $this->company);
        $page = Livewire::test(CreateAccount::class)
            ->fillForm(['is_manual_counterparty' => true, 'manual_counterparty_name' => 'Despesa criada no BPO', 'due_date' => today()->toDateString(),
                'due_amount' => '240,00', 'amount_input_mode' => 'total', 'installment_count' => 2, 'company_id' => 999999]);
        $page->call('create')->assertHasNoFormErrors();
        $account = AccountPayable::where('manual_counterparty_name', 'Despesa criada no BPO')->sole();
        $this->assertSame($this->company->id, $account->company_id);
        $this->assertSame($this->bpo->id, $account->created_by);
        $this->assertSame(2, $account->installments()->count());
        $this->assertSame(240.0, $account->due_amount);
        $page->assertRedirect(AccountPayableResource::getUrl('view', ['record' => $account], panel: 'bpo', tenant: $this->company));
        Livewire::test(EditAccount::class, ['record' => $account->id])
            ->fillForm(['description' => 'Descrição corrigida no BPO'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('Descrição corrigida no BPO', $account->fresh()->description);
    }

    public function test_bank_import_view_reuses_the_reconciliation_workbench_and_rejects_revoked_parent_access(): void
    {
        $financial = FinancialAccount::create(['company_id' => $this->company->id, 'name' => 'Sicoob BPO', 'type' => 'bank', 'is_active' => true, 'created_by' => $this->bpo->id]);
        $import = BankStatementImport::create(['company_id' => $this->company->id, 'financial_account_id' => $financial->id, 'source' => 'ofx',
            'status' => 'completed', 'file_name' => 'bpo.ofx', 'line_count' => 1, 'created_by' => $this->bpo->id]);
        $line = $import->lines()->create(['company_id' => $this->company->id, 'financial_account_id' => $financial->id, 'transaction_date' => today(), 'amount' => 100,
            'description' => 'Lançamento BPO', 'external_id' => 'BPO-1', 'transaction_key' => 'bpo-1', 'reconciliation_status' => 'pending', 'metadata' => ['direction' => 'outflow']]);
        $this->authenticate(company: $this->company);
        $this->get(BankStatementImportResource::getUrl('view', ['record' => $import], panel: 'bpo', tenant: $this->company))->assertOk()
            ->assertSee('bpo-account-top-grid', false)->assertSee('Sicoob BPO')->assertSee('bpo.ofx')->assertSeeLivewire(LinesRelationManager::class);
        $page = Livewire::test(LinesRelationManager::class, [
            'ownerRecord' => $import, 'pageClass' => ViewImport::class,
        ])->assertCanSeeTableRecords([$line]);
        $page->selectTableRecords([$line->id])->mountAction(TestAction::make('reconcile_multiple')->table()->bulk());
        $this->assertStringContainsString('Registros do sistema', $page->getMountedActionModalHtml());
        $this->teams->endBpo($this->owner, $this->company, $this->engagement->id);
        $page->call('$refresh')->assertForbidden();
    }

    public function test_existing_legacy_company_access_is_preserved_but_bpo_uses_explicit_scopes(): void
    {
        $legacy = User::factory()->create();
        $legacy->companies()->attach($this->company, ['role' => 'member', 'is_active' => true]);
        $own = $this->payable($legacy, 'Registro próprio do legado');
        $foreign = $this->payable($this->owner, 'Registro de outra pessoa do legado');
        $role = $this->role('BPO legado limitado', ['account_payables.view' => 'own']);
        $this->teams->authorizeBpo($this->owner, $this->company, $legacy, [$role->id], false, []);
        $this->assertFalse(app(CompanyAuthorization::class)->isManaged($legacy, $this->company));
        $this->authenticate($legacy, $this->company);
        $this->assertFalse(app(UserAccessService::class)->allows($legacy, $this->company, 'cash_movements.access'));
        $this->get(AccountPayableResource::getUrl(panel: 'bpo', tenant: $this->company))->assertOk()->assertSee('Registro próprio do legado')->assertDontSee('Registro de outra pessoa do legado');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->assertTrue(app(UserAccessService::class)->allows($legacy, $this->company, 'cash_movements.access'));
        $this->get(\App\Filament\Clusters\Financial\Resources\AccountPayables\AccountPayableResource::getUrl(tenant: $this->company))
            ->assertOk()->assertSee('Registro próprio do legado')->assertSee('Registro de outra pessoa do legado');
    }

    public function test_upgrade_enables_the_new_panel_for_preexisting_bpo_assignments_without_changing_actions(): void
    {
        $role = CompanyRoleAssignment::where('bpo_engagement_id', $this->engagement->id)->sole()->role;
        $permissions = $role->permissions;
        $role->update(['panels' => ['admin', 'operation', 'mobile']]);
        $this->assertFalse(app(BpoAccessService::class)->canAccessPanel($this->bpo));
        $migration = require database_path('migrations/2026_10_25_000002_enable_bpo_panel_for_existing_engagements.php');
        $migration->up();
        $this->assertTrue(app(BpoAccessService::class)->canAccessPanel($this->bpo));
        $this->assertSame($permissions, $role->fresh()->permissions);
    }

    public function test_read_only_reconciliation_access_does_not_offer_mutating_actions(): void
    {
        $reader = User::factory()->create();
        $role = $this->role('Consulta de extratos', ['bank_statements.view' => 'company']);
        $this->teams->authorizeBpo($this->owner, $this->company, $reader, [$role->id], false, []);
        $financial = FinancialAccount::create(['company_id' => $this->company->id, 'name' => 'Banco de consulta', 'type' => 'bank', 'is_active' => true, 'created_by' => $this->bpo->id]);
        $import = BankStatementImport::create(['company_id' => $this->company->id, 'financial_account_id' => $financial->id, 'source' => 'ofx', 'status' => 'completed', 'created_by' => $this->bpo->id]);
        $line = $import->lines()->create(['company_id' => $this->company->id, 'financial_account_id' => $financial->id, 'transaction_date' => today(), 'amount' => 50,
            'description' => 'Lançamento somente leitura', 'external_id' => 'READ-1', 'transaction_key' => 'read-1', 'reconciliation_status' => 'pending', 'metadata' => ['direction' => 'outflow']]);
        $this->authenticate($reader, $this->company);
        Livewire::test(LinesRelationManager::class, [
            'ownerRecord' => $import, 'pageClass' => ViewImport::class,
        ])->assertCanSeeTableRecords([$line])->assertActionHidden(TestAction::make('reconcile_multiple')->table($line));
    }
}
