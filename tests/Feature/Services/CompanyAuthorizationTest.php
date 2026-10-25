<?php

namespace Tests\Feature\Services;

use App\Filament\Clusters\Sales\Resources\Quotes\QuoteResource;
use App\Filament\Clusters\Settings\Pages\CompanyPreferences;
use App\Filament\Pages\CompanyTeam;
use App\Models\AccountPayableInstallment;
use App\Models\Company;
use App\Models\CompanyRole;
use App\Models\CompanyRoleAssignment;
use App\Models\Quote;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\Access\CompanyAuthorization;
use App\Services\Access\CompanyTeamService;
use App\Services\Access\UserAccessService;
use App\Services\AccountPayable\AccountPayableService;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class CompanyAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function company(): array
    {
        $owner = User::factory()->create();
        $company = Company::factory()->create(['created_by' => $owner->id]);
        $owner->companies()->attach($company, ['role' => 'owner', 'is_active' => true]);

        return [$owner, $company, app(CompanyTeamService::class)];
    }

    private function role(User $owner, Company $company, array $permissions, string $name = 'Papel'): CompanyRole
    {
        return app(CompanyTeamService::class)->saveRole($owner, $company, ['name' => $name, 'permissions' => $permissions, 'panels' => ['admin', 'operation', 'mobile']]);
    }

    public function test_own_visibility_and_company_visibility_accumulate_per_action_and_per_company(): void
    {
        [$owner, $company, $teams] = $this->company();
        [$otherOwner, $otherCompany] = $this->company();
        $user = User::factory()->create();
        $own = Quote::factory()->create(['company_id' => $company->id, 'created_by' => $user->id]);
        $foreign = Quote::factory()->create(['company_id' => $company->id, 'created_by' => $owner->id]);
        Quote::factory()->create(['company_id' => $otherCompany->id, 'created_by' => $user->id]);
        $role = $this->role($owner, $company, ['quotes.view' => 'own', 'quotes.update' => 'own']);
        $teams->assign($owner, $company, $user, [$role->id]);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($company);
        $this->assertSame([$own->id], Quote::pluck('id')->all());
        $this->assertFalse(Gate::allows('update', $foreign));
        $this->assertSame(1, Quote::count());
        $broad = $this->role($owner, $company, ['quotes.view' => 'company'], 'Consulta ampla');
        $teams->assign($owner, $company, $user, [$role->id, $broad->id]);
        $this->assertEqualsCanonicalizing([$own->id, $foreign->id], Quote::pluck('id')->all());
        $this->assertFalse(app(CompanyAuthorization::class)->allowsRecord($user, $company, 'quotes.update', $foreign));
        $this->assertTrue(app(CompanyAuthorization::class)->allowsRecord($user, $company, 'quotes.update', $own));
    }

    public function test_assigned_orders_are_not_confused_with_orders_created_by_the_operator(): void
    {
        [$owner, $company, $teams] = $this->company();
        $technician = User::factory()->create();
        $assigned = ServiceOrder::factory()->create(['company_id' => $company->id, 'created_by' => $owner->id, 'technician_id' => $technician->id]);
        ServiceOrder::factory()->create(['company_id' => $company->id, 'created_by' => $technician->id, 'technician_id' => $owner->id]);
        $role = $this->role($owner, $company, ['service_orders.view' => 'assigned', 'service_orders.update' => 'assigned']);
        $teams->assign($owner, $company, $technician, [$role->id]);
        $this->actingAs($technician);
        $this->assertSame([$assigned->id], ServiceOrder::pluck('id')->all());
        $assigned->solution = 'Atendido';
        $assigned->save();
        $this->assertSame('Atendido', $assigned->fresh()->solution);
        $this->expectException(AuthorizationException::class);
        $assigned->technician_id = $owner->id;
        $assigned->save();
    }

    public function test_read_only_user_cannot_save_a_visible_record_and_revocation_changes_queries_immediately(): void
    {
        [$owner, $company, $teams] = $this->company();
        $user = User::factory()->create();
        $quote = Quote::factory()->create(['company_id' => $company->id]);
        $role = $this->role($owner, $company, ['quotes.view' => 'company']);
        $teams->assign($owner, $company, $user, [$role->id]);
        $this->actingAs($user);
        $this->assertSame(1, Quote::count());
        try {
            $quote->update(['description' => 'Não autorizado']);
            $this->fail('A escrita deveria ser bloqueada.');
        } catch (AuthorizationException) {
            $this->assertNotSame('Não autorizado', DB::table('quotes')->where('id', $quote->id)->value('description'));
        }
        $assignment = CompanyRoleAssignment::where('user_id', $user->id)->firstOrFail();
        $teams->revokeAssignment($owner, $company, $assignment->id);
        $this->assertSame(0, Quote::count());
        $this->assertFalse(app(UserAccessService::class)->canAccessPanel($user, $company, 'admin'));
    }

    public function test_creator_is_server_owned_and_company_cannot_be_changed(): void
    {
        [$owner, $company, $teams] = $this->company();
        $user = User::factory()->create();
        $role = $this->role($owner, $company, ['quotes.view' => 'own', 'quotes.create' => 'company', 'quotes.update' => 'own']);
        $teams->assign($owner, $company, $user, [$role->id]);
        $this->actingAs($user);
        $quote = Quote::factory()->create(['company_id' => $company->id, 'created_by' => $owner->id]);
        $this->assertSame($user->id, $quote->created_by);
        $this->expectException(AuthorizationException::class);
        $quote->update(['created_by' => $owner->id]);
    }

    public function test_bpo_delegation_is_bounded_and_ending_it_preserves_direct_access(): void
    {
        [$owner, $company, $teams] = $this->company();
        $bpo = User::factory()->create();
        $assistant = User::factory()->create();
        $full = $this->role($owner, $company, ['quotes.view' => 'company'], 'BPO');
        $limited = $this->role($owner, $company, ['quotes.view' => 'own'], 'Auxiliar');
        $direct = $this->role($owner, $company, ['service_orders.view' => 'assigned'], 'Técnico');
        $engagement = $teams->authorizeBpo($owner, $company, $bpo, [$full->id], true, ['quotes.view' => 'company']);
        $teams->assign($owner, $company, $assistant, [$direct->id]);
        $teams->assign($bpo, $company, $assistant, [$limited->id], $engagement->id);
        $access = app(CompanyAuthorization::class);
        $this->assertSame(['own'], $access->scopes($assistant, $company, 'quotes.view'));
        try {
            $teams->assign($bpo, $company, $assistant, [$direct->id], $engagement->id);
            $this->fail('O limite de delegação deveria bloquear o papel.');
        } catch (AuthorizationException) {
            $this->assertSame(['own'], $access->scopes($assistant, $company, 'quotes.view'));
        }
        $teams->endBpo($owner, $company, $engagement->id);
        $this->assertSame([], $access->scopes($assistant, $company, 'quotes.view'));
        $this->assertSame(['assigned'], $access->scopes($assistant, $company, 'service_orders.view'));
        $this->assertSame([], $access->scopes($bpo, $company, 'quotes.view'));
    }

    public function test_user_and_role_ids_from_another_company_cannot_be_used_to_escalate_access(): void
    {
        [$owner, $company, $teams] = $this->company();
        [$otherOwner, $otherCompany] = $this->company();
        $foreign = $this->role($otherOwner, $otherCompany, ['team.manage' => 'company']);
        $this->expectException(AuthorizationException::class);
        $teams->assign($owner, $company, User::factory()->create(), [$foreign->id]);
    }

    public function test_last_company_administrator_cannot_be_demoted_or_suspended(): void
    {
        [$owner, $company, $teams] = $this->company();
        $admin = $this->role($owner, $company, ['team.manage' => 'company'], 'Administrador');
        $teams->assign($owner, $company, $owner, [$admin->id]);
        try {
            $teams->suspend($owner, $company, $owner);
            $this->fail('O último administrador deve ser preservado.');
        } catch (AuthorizationException) {
            $this->assertTrue($owner->canAccessTenant($company));
        }
        $this->expectException(AuthorizationException::class);
        $teams->saveRole($owner, $company, ['name' => 'Administrador', 'permissions' => [], 'panels' => ['admin']], $admin->id);
    }

    public function test_invitation_acceptance_checks_email_expiration_and_current_grant_authority(): void
    {
        [$owner, $company, $teams] = $this->company();
        $user = User::factory()->create();
        $role = $this->role($owner, $company, ['quotes.view' => 'own']);
        $url = $teams->invite($owner, $company, $user->email, [$role->id]);
        $token = basename($url);
        try {
            $teams->accept(User::factory()->create(), $token);
            $this->fail('Outro e-mail não pode aceitar.');
        } catch (AuthorizationException) {
            $this->assertFalse($user->canAccessTenant($company));
        }
        $teams->accept($user, $token);
        $this->assertTrue($user->canAccessTenant($company));
        $this->assertSame(['own'], app(CompanyAuthorization::class)->scopes($user, $company, 'quotes.view'));
        $this->expectException(AuthorizationException::class);
        $teams->accept($user, $token);
    }

    public function test_expired_invitation_does_not_add_membership(): void
    {
        [$owner, $company, $teams] = $this->company();
        $user = User::factory()->create();
        $role = $this->role($owner, $company, ['quotes.view' => 'company']);
        $url = $teams->invite($owner, $company, $user->email, [$role->id]);
        $this->travel(8)->days();
        $this->expectException(AuthorizationException::class);
        $teams->accept($user, basename($url));
    }

    public function test_owner_can_manage_team_in_filament_and_operator_cannot(): void
    {
        [$owner, $company, $teams] = $this->company();
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($company);
        $this->get(CompanyTeam::getUrl(tenant: $company))->assertOk()->assertSee('Equipe e acessos');
        Livewire::test(CompanyTeam::class)->callAction('templates')->assertHasNoActionErrors();
        $this->assertDatabaseHas('company_roles', ['company_id' => $company->id, 'name' => 'Vendedor']);
        $user = User::factory()->create();
        $role = $this->role($owner, $company, ['quotes.view' => 'own']);
        $teams->assign($owner, $company, $user, [$role->id]);
        $this->actingAs($user);
        $this->get(CompanyTeam::getUrl(tenant: $company))->assertForbidden();
    }

    public function test_legacy_members_keep_existing_access_until_explicit_migration(): void
    {
        [$owner, $company] = $this->company();
        $legacy = User::factory()->create();
        $legacy->companies()->attach($company, ['role' => 'member', 'is_active' => true]);
        $this->assertFalse(app(CompanyAuthorization::class)->isManaged($legacy, $company));
        $this->assertTrue(app(UserAccessService::class)->allows($legacy, $company, 'account_payables.access', 'operation'));
    }

    public function test_scoped_user_cannot_write_to_an_unlinked_company_or_recover_access_by_detaching_membership(): void
    {
        [$owner, $company, $teams] = $this->company();
        [, $foreignCompany] = $this->company();
        $user = User::factory()->create();
        Quote::factory()->create(['company_id' => $company->id, 'created_by' => $user->id]);
        $role = $this->role($owner, $company, ['quotes.view' => 'company', 'quotes.create' => 'company']);
        $teams->assign($owner, $company, $user, [$role->id]);
        $this->actingAs($user);
        try {
            Quote::factory()->create(['company_id' => $foreignCompany->id]);
            $this->fail('Escrita em outra empresa deveria ser negada.');
        } catch (AuthorizationException) {
            $this->assertSame(0, DB::table('quotes')->where('company_id', $foreignCompany->id)->count());
        }
        $user->companies()->detach($company);
        $this->assertSame(0, Quote::count());
    }

    public function test_invitation_can_create_an_account_and_requires_post_to_accept(): void
    {
        [$owner, $company, $teams] = $this->company();
        $role = $this->role($owner, $company, ['quotes.view' => 'own']);
        $url = $teams->invite($owner, $company, 'novo@example.com', [$role->id]);
        $this->get($url)->assertOk()->assertSee('Crie sua conta');
        $this->assertDatabaseMissing('users', ['email' => 'novo@example.com']);
        $this->post(route('company-access.invitation.accept', basename($url)), [
            'name' => 'Novo integrante', 'password' => 'senha-segura-123', 'password_confirmation' => 'senha-segura-123',
            'is_admin' => true, 'management_role' => 'super_admin',
        ])->assertOk()->assertSee('confirmado');
        $user = User::where('email', 'novo@example.com')->firstOrFail();
        $this->assertFalse($user->is_admin);
        $this->assertNull($user->management_role);
        $this->assertTrue($user->canAccessTenant($company));
        $this->get($url)->assertGone();
    }

    public function test_bpo_cannot_reactivate_suspended_auxiliary_and_existing_direct_access_is_preserved(): void
    {
        [$owner, $company, $teams] = $this->company();
        $bpo = User::factory()->create();
        $assistant = User::factory()->create();
        $role = $this->role($owner, $company, ['quotes.view' => 'company']);
        $engagement = $teams->authorizeBpo($owner, $company, $bpo, [$role->id], true, ['quotes.view' => 'company']);
        $assistant->companies()->attach($company, ['role' => 'member', 'is_active' => true]);
        $teams->assign($bpo, $company, $assistant, [$role->id], $engagement->id);
        $this->assertFalse(app(CompanyAuthorization::class)->isManaged($assistant, $company));
        $teams->suspend($owner, $company, $assistant);
        $this->expectException(AuthorizationException::class);
        $teams->assign($bpo, $company, $assistant, [$role->id], $engagement->id);
    }

    public function test_company_role_editor_and_assignment_actions_work_and_enforce_read_only_routes(): void
    {
        [$owner, $company, $teams] = $this->company();
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($company);
        Livewire::test(CompanyTeam::class)->callAction('role', data: [
            'role_id' => null, 'name' => 'Consulta de propostas', 'panels' => ['admin'],
            'grants' => [['permission' => 'quotes.view', 'scope' => 'own']],
        ])->assertHasNoActionErrors();
        $role = CompanyRole::where('name', 'Consulta de propostas')->firstOrFail();
        $user = User::factory()->create();
        Livewire::test(CompanyTeam::class)->callAction('assign', data: ['email' => $user->email, 'role_ids' => [$role->id], 'engagement_id' => null])->assertHasNoActionErrors();
        $this->actingAs($user);
        $this->get(QuoteResource::getUrl('index', tenant: $company))->assertOk();
        $this->get(QuoteResource::getUrl('create', tenant: $company))->assertForbidden();
        $this->get(CompanyPreferences::getUrl(tenant: $company))->assertForbidden();
    }

    public function test_financial_role_can_create_payable_with_installments_and_own_visibility_inherits_from_header(): void
    {
        [$owner, $company, $teams] = $this->company();
        $user = User::factory()->create();
        $role = $this->role($owner, $company, ['account_payables.view' => 'own', 'account_payables.create' => 'company', 'account_payables.update' => 'own']);
        $teams->assign($owner, $company, $user, [$role->id]);
        $this->actingAs($user);
        $service = app(AccountPayableService::class);
        $payable = $service->create([
            'company_id' => $company->id, 'manual_counterparty_name' => 'Fornecedor',
            'due_date' => today()->toDateString(), 'due_amount' => 100, 'installment_count' => 2,
        ], $user->id);
        $this->assertNotNull($payable, $service->getMessage());
        $this->assertSame($user->id, (int) $payable->created_by);
        $this->assertSame(2, AccountPayableInstallment::count());
        $teams->revokeAssignment($owner, $company, CompanyRoleAssignment::where('user_id', $user->id)->firstOrFail()->id);
        $this->assertSame(0, AccountPayableInstallment::count());
    }

    public function test_delegated_role_cannot_be_expanded_past_the_company_ceiling(): void
    {
        [$owner, $company, $teams] = $this->company();
        $bpo = User::factory()->create();
        $assistant = User::factory()->create();
        $role = $this->role($owner, $company, ['quotes.view' => 'company'], 'Responsável');
        $limited = $this->role($owner, $company, ['quotes.view' => 'own'], 'Auxiliar');
        $engagement = $teams->authorizeBpo($owner, $company, $bpo, [$role->id], true, ['quotes.view' => 'company']);
        $teams->assign($bpo, $company, $assistant, [$limited->id], $engagement->id);
        try {
            $teams->saveRole($owner, $company, [
                'name' => 'Auxiliar', 'permissions' => ['quotes.view' => 'own', 'quotes.delete' => 'company'], 'panels' => ['admin'],
            ], $limited->id);
            $this->fail('A expansão deveria exigir alteração da autorização de atendimento.');
        } catch (AuthorizationException) {
            $this->assertArrayNotHasKey('quotes.delete', $limited->fresh()->permissions);
        }
        $teams->revokeAssignment($owner, $company, CompanyRoleAssignment::where('user_id', $bpo->id)->firstOrFail()->id);
        $this->assertSame([], app(CompanyAuthorization::class)->scopes($assistant, $company, 'quotes.view'));
    }

    public function test_existing_account_without_companies_can_authenticate_to_accept_an_invitation(): void
    {
        $user = User::factory()->create();
        $this->assertTrue($user->canAccessPanel(Filament::getPanel('mobile')));
        $this->assertTrue($user->canAccessPanel(Filament::getPanel('admin')));
        $this->assertCount(0, $user->getTenants(Filament::getPanel('mobile')));
    }

    public function test_access_migration_can_be_rolled_back_without_deleting_existing_companies_or_links(): void
    {
        [$owner, $company] = $this->company();
        $migration = require database_path('migrations/2026_10_25_000001_create_company_access_roles.php');
        $migration->down();
        $this->assertDatabaseHas('companies', ['id' => $company->id]);
        $this->assertDatabaseHas('company_user', ['company_id' => $company->id, 'user_id' => $owner->id]);
        $migration->up();
        $this->assertFalse(app(CompanyAuthorization::class)->isManaged($owner, $company));
    }
}
