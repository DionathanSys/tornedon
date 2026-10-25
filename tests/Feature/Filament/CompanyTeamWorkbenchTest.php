<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\CompanyTeam;
use App\Models\BpoEngagement;
use App\Models\Company;
use App\Models\CompanyRole;
use App\Models\User;
use App\Services\Access\CompanyTeamService;
use App\Services\Access\PermissionCatalog;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CompanyTeamWorkbenchTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Company $company;

    private CompanyTeamService $teams;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->company = Company::factory()->create();
        $this->owner->companies()->attach($this->company, ['role' => 'owner', 'is_active' => true]);
        $this->teams = app(CompanyTeamService::class);
        $this->actingAs($this->owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->company);
    }

    private function role(string $name, array $permissions): CompanyRole
    {
        return $this->teams->saveRole($this->owner, $this->company, ['name' => $name, 'permissions' => $permissions, 'panels' => ['admin', 'operation']]);
    }

    public function test_people_workspace_explains_company_and_bpo_access_and_searches_by_role(): void
    {
        $person = User::factory()->create(['name' => 'Pessoa da equipe']);
        $bpo = User::factory()->create(['name' => 'Responsável financeiro']);
        $direct = $this->role('Consulta comercial', ['quotes.view' => 'company']);
        $external = $this->role('Operador financeiro', ['account_payables.view' => 'company']);
        $this->teams->assign($this->owner, $this->company, $person, [$direct->id]);
        $engagement = $this->teams->authorizeBpo($this->owner, $this->company, $bpo, [$external->id], true, ['account_payables.view' => 'company']);
        $this->teams->assign($bpo, $this->company, $person, [$external->id], $engagement->id);

        Livewire::test(CompanyTeam::class)->call('selectPerson', $person->id)
            ->assertSee('Concedido pela empresa')->assertSee('Não depende de BPO')
            ->assertSee('Pelo atendimento de Responsável financeiro')->assertSee('Ver permissões resultantes')
            ->set('search', 'consulta comercial')->assertSee('Pessoa da equipe')->assertDontSee('Nenhuma pessoa encontrada')
            ->call('changeTab', 'roles')->assertSee('Resumo por módulo')->assertSee('Orçamentos');
    }

    public function test_contextual_role_editor_preserves_existing_grants_and_edits_only_the_selected_role(): void
    {
        $role = $this->role('Vendas', ['quotes.view' => 'own', 'quotes.update' => 'own', 'products.view' => 'company', 'quotes.issue' => 'company']);
        $other = $this->role('Outro papel', ['quotes.view' => 'company']);
        $page = Livewire::test(CompanyTeam::class)->mountAction('role', ['role_id' => $role->id]);
        $this->assertStringContainsString('Permissões por módulo', $page->getMountedActionModalHtml());
        $this->assertStringContainsString('Buscar módulo ou ação', $page->getMountedActionModalHtml());
        $page->assertActionDataSet(['name' => 'Vendas', 'role_id' => $role->id]);
        $page->fillForm(['name' => 'Vendas revisado', 'permissions' => ['quotes.view' => 'company', 'quotes.update' => 'own', 'products.view' => 'company', 'quotes.issue' => 'company']])
            ->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame('company', $role->fresh()->permissions['quotes.view']);
        $this->assertSame('own', $role->fresh()->permissions['quotes.update']);
        $this->assertSame('company', $role->fresh()->permissions['quotes.issue']);
        $this->assertSame('Outro papel', $other->fresh()->name);
    }

    public function test_contextual_person_editor_prefills_the_selected_source_without_replacing_other_sources(): void
    {
        $person = User::factory()->create();
        $bpo = User::factory()->create();
        $direct = $this->role('Pela empresa', ['quotes.view' => 'own']);
        $external = $this->role('Pelo BPO', ['quotes.view' => 'company']);
        $replacement = $this->role('Novo papel', ['quotes.view' => 'company', 'quotes.update' => 'own']);
        $this->teams->assign($this->owner, $this->company, $person, [$direct->id]);
        $engagement = $this->teams->authorizeBpo($this->owner, $this->company, $bpo, [$external->id], true, ['quotes.view' => 'company']);
        $this->teams->assign($bpo, $this->company, $person, [$external->id], $engagement->id);

        Livewire::test(CompanyTeam::class)->mountAction('assign', ['person' => $person->id])
            ->assertActionDataSet(['email' => $person->email, 'role_ids' => [$direct->id], 'engagement_id' => null])
            ->fillForm(['role_ids' => [$replacement->id]])->callMountedAction()->assertHasNoActionErrors();
        $this->assertDatabaseHas('company_role_assignments', ['user_id' => $person->id, 'company_role_id' => $replacement->id, 'bpo_engagement_id' => null, 'is_active' => true]);
        $this->assertDatabaseHas('company_role_assignments', ['user_id' => $person->id, 'company_role_id' => $external->id, 'bpo_engagement_id' => $engagement->id, 'is_active' => true]);
    }

    public function test_resulting_permissions_are_read_only_and_reflect_all_current_scopes(): void
    {
        $person = User::factory()->create();
        $own = $this->role('Próprios', ['service_orders.view' => 'own']);
        $assigned = $this->role('Atribuídos', ['service_orders.view' => 'assigned']);
        $this->teams->assign($this->owner, $this->company, $person, [$own->id, $assigned->id]);
        $page = Livewire::test(CompanyTeam::class)->mountAction('inspect', ['person' => $person->id]);
        $state = $page->get('mountedActions.0.data.permissions');
        $this->assertEqualsCanonicalizing(['own', 'assigned'], $state['service_orders.view'] ?? $state['service_orders']['view']);
        $this->assertStringContainsString('Permissões atuais na empresa', $page->getMountedActionModalHtml());
        $this->assertStringContainsString('Próprios ou atribuídos', $page->getMountedActionModalHtml());
        $this->teams->suspend($this->owner, $this->company, $person);
        $page->unmountAction()->mountAction('inspect', ['person' => $person->id]);
        $this->assertSame([], $page->get('mountedActions.0.data.permissions'));
    }

    public function test_auxiliary_management_shows_only_delegable_roles_and_people_from_its_own_engagement(): void
    {
        $bpo = User::factory()->create();
        $assistant = User::factory()->create(['name' => 'Auxiliar visível']);
        $unrelated = User::factory()->create(['name' => 'Pessoa de outro atendimento']);
        $allowed = $this->role('Delegação permitida', ['quotes.view' => 'company']);
        $forbidden = $this->role('Privilégio não delegável', ['team.manage' => 'company']);
        $engagement = $this->teams->authorizeBpo($this->owner, $this->company, $bpo, [$allowed->id], true, ['quotes.view' => 'company']);
        $this->teams->assign($bpo, $this->company, $assistant, [$allowed->id], $engagement->id);
        $this->teams->assign($this->owner, $this->company, $unrelated, [$allowed->id]);
        $this->actingAs($bpo);
        $page = Livewire::test(CompanyTeam::class)->assertSee('Auxiliar visível')->assertDontSee('Pessoa de outro atendimento')
            ->mountAction('assign', ['engagement' => $engagement->id]);
        $this->assertStringContainsString('Delegação permitida', $page->getMountedActionModalHtml());
        $this->assertStringNotContainsString('Privilégio não delegável', $page->getMountedActionModalHtml());
        Livewire::test(CompanyTeam::class)->call('selectPerson', $unrelated->id)->assertNotFound();
    }

    public function test_contextual_actions_cannot_open_a_role_or_person_from_another_company(): void
    {
        $foreign = Company::factory()->create();
        $role = CompanyRole::create(['company_id' => $foreign->id, 'name' => 'Papel externo', 'permissions' => [], 'panels' => ['admin']]);
        Livewire::test(CompanyTeam::class)->mountAction('role', ['role_id' => $role->id])->assertNotFound();
        Livewire::test(CompanyTeam::class)->call('selectPerson', User::factory()->create()->id)->assertNotFound();
    }

    public function test_editor_uses_budget_terminology_and_does_not_offer_financial_actions_for_budgets(): void
    {
        $group = collect(PermissionCatalog::editorGroups())->firstWhere('key', 'quotes');
        $this->assertSame('Orçamentos', $group['label']);
        $this->assertNotContains('quotes.settle', array_column($group['permissions'], 'key'));
        $this->assertNotContains('quotes.assign', array_column($group['permissions'], 'key'));
        $this->assertArrayHasKey('quotes.settle', PermissionCatalog::labels()); // Previously saved keys remain compatible.
    }

    public function test_workspace_is_available_in_existing_panels_and_keeps_the_operation_layout(): void
    {
        foreach ([
            'admin' => CompanyTeam::class,
            'mobile' => \App\Filament\Mobile\Pages\CompanyTeam::class,
            'operation' => \App\Filament\Operation\Pages\CompanyTeam::class,
        ] as $panel => $page) {
            Filament::setCurrentPanel(Filament::getPanel($panel));
            Filament::setTenant($this->company);
            $response = $this->get($page::getUrl(tenant: $this->company))->assertOk()->assertSee('Papéis e permissões')->assertSee('Convidar pessoa');
            if ($panel === 'operation') {
                $response->assertSee('operation-ui', false);
            }
        }
    }

    public function test_authorizing_bpo_limits_delegation_to_the_roles_selected_in_the_dialog(): void
    {
        $bpo = User::factory()->create();
        $role = $this->role('Financeiro delimitado', ['account_payables.view' => 'company', 'account_payables.update' => 'own']);
        Livewire::test(CompanyTeam::class)->callAction('bpo', data: [
            'email' => $bpo->email, 'role_ids' => [$role->id], 'can_delegate' => true,
            'delegable_permissions' => ['account_payables.view' => 'company', 'account_payables.update' => 'company', 'quotes.view' => 'company'],
        ])->assertHasNoActionErrors();
        $engagement = BpoEngagement::where('company_id', $this->company->id)->sole();
        $this->assertSame(['account_payables.view' => 'company'], $engagement->delegable_permissions);
    }
}
