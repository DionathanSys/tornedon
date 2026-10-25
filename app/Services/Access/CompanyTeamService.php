<?php

namespace App\Services\Access;

use App\Models\BpoEngagement;
use App\Models\Company;
use App\Models\CompanyAccessInvitation;
use App\Models\CompanyMembership;
use App\Models\CompanyRole;
use App\Models\CompanyRoleAssignment;
use App\Models\User;
use App\Notifications\CompanyTeamInvitation;
use App\Services\Audit\AuditRecorder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CompanyTeamService
{
    public function __construct(private CompanyAuthorization $access) {}

    public function assertManager(User $actor, Company $company): void
    {
        throw_unless($this->access->canManageTeam($actor, $company), AuthorizationException::class, 'Você não pode administrar a equipe desta empresa.');
    }

    public function saveRole(User $actor, Company $company, array $data, ?int $roleId = null): CompanyRole
    {
        return DB::transaction(function () use ($actor, $company, $data, $roleId): CompanyRole {
            $this->lock($company);
            $this->assertManager($actor, $company);
            $validated = Validator::make($data, [
                'name' => ['required', 'string', 'max:100', Rule::unique('company_roles')->where('company_id', $company->id)->ignore($roleId)],
                'permissions' => ['present', 'array'],
                'panels' => ['required', 'array', 'min:1'],
                'panels.*' => [Rule::in(array_keys(PanelAccess::options()))],
            ])->validate();
            $validated['permissions'] = $this->validatePermissions($validated['permissions']);
            $role = $roleId ? CompanyRole::where('company_id', $company->id)->findOrFail($roleId) : new CompanyRole(['company_id' => $company->id]);
            // A role used by a BPO may not be expanded behind the delegation ceiling.
            foreach (CompanyRoleAssignment::where('company_role_id', $roleId)->whereNotNull('bpo_engagement_id')->where('is_active', true)->with('engagement')->get() as $assignment) {
                if ($assignment->engagement?->is_active && $assignment->user_id !== $assignment->engagement->responsible_id) {
                    $this->assertWithinCeiling($validated['permissions'], $assignment->engagement->delegable_permissions);
                }
            }
            $role->fill($validated)->save();
            $this->assertAdministratorRemains($company);
            $this->audit($actor, $role, 'access.role.updated', 'Papel da empresa atualizado');

            return $role;
        });
    }

    public function installTemplates(User $actor, Company $company): void
    {
        DB::transaction(function () use ($actor, $company): void {
            $this->lock($company);
            $this->assertManager($actor, $company);
            foreach (PermissionCatalog::templates() as $name => $permissions) {
                CompanyRole::firstOrCreate(['company_id' => $company->id, 'name' => $name], ['permissions' => $permissions, 'panels' => array_keys(PanelAccess::options())]);
            }
        });
    }

    public function assign(User $actor, Company $company, User $target, array $roleIds, ?int $engagementId = null): void
    {
        DB::transaction(function () use ($actor, $company, $target, $roleIds, $engagementId): void {
            $this->lock($company);
            $roles = $this->roles($company, $roleIds);
            $engagement = $this->authorizeAssignment($actor, $company, $roles, $engagementId);
            if ($engagement && $this->access->canManageTeam($actor, $company)) {
                foreach ($roles as $role) {
                    if (! in_array('bpo', $role->panels, true)) {
                        $role->update(['panels' => [...$role->panels, 'bpo']]);
                        $this->audit($actor, $role, 'access.role.updated', 'Painel BPO habilitado para papel autorizado no atendimento');
                    }
                }
            }
            $this->ensureMembership($target, $company, $this->access->canManageTeam($actor, $company), $engagement === null);
            $query = CompanyRoleAssignment::where('company_id', $company->id)->where('user_id', $target->id);
            $engagement ? $query->where('bpo_engagement_id', $engagement->id) : $query->whereNull('bpo_engagement_id');
            $query->update(['is_active' => false]);
            foreach ($roles as $role) {
                $assignment = CompanyRoleAssignment::create([
                    'company_id' => $company->id, 'user_id' => $target->id, 'company_role_id' => $role->id,
                    'granted_by' => $actor->id, 'bpo_engagement_id' => $engagement?->id, 'is_active' => true,
                ]);
                $this->audit($actor, $assignment, 'access.assignment.created', 'Papel atribuído ao usuário');
            }
            $this->assertAdministratorRemains($company);
        });
    }

    public function suspend(User $actor, Company $company, User $target): void
    {
        DB::transaction(function () use ($actor, $company, $target): void {
            $this->lock($company);
            $this->assertManager($actor, $company);
            $record = CompanyMembership::where('company_id', $company->id)->where('user_id', $target->id)->firstOrFail();
            $record->update(['is_active' => false]);
            $this->assertAdministratorRemains($company);
            $this->audit($actor, $record, 'access.membership.suspended', 'Vínculo do usuário suspenso nesta empresa');
        });
    }

    public function revokeAssignment(User $actor, Company $company, int $assignmentId): void
    {
        DB::transaction(function () use ($actor, $company, $assignmentId): void {
            $this->lock($company);
            $assignment = CompanyRoleAssignment::where('company_id', $company->id)->findOrFail($assignmentId);
            if ($assignment->bpo_engagement_id && ! $this->access->canManageTeam($actor, $company)) {
                $this->authorizeAssignment($actor, $company, collect(), $assignment->bpo_engagement_id);
            } else {
                $this->assertManager($actor, $company);
            }
            $assignment->update(['is_active' => false]);
            $this->assertAdministratorRemains($company);
            $this->audit($actor, $assignment, 'access.assignment.revoked', 'Atribuição de papel revogada');
        });
    }

    public function authorizeBpo(User $actor, Company $company, User $responsible, array $roleIds, bool $canDelegate, array $ceiling): BpoEngagement
    {
        return DB::transaction(function () use ($actor, $company, $responsible, $roleIds, $canDelegate, $ceiling): BpoEngagement {
            $this->lock($company);
            $this->assertManager($actor, $company);
            $ceiling = $this->validatePermissions($ceiling);
            throw_if(isset($ceiling['team.manage']), AuthorizationException::class, 'Gestão da equipe não é delegável pelo atendimento BPO.');
            $engagement = BpoEngagement::create([
                'company_id' => $company->id, 'responsible_id' => $responsible->id, 'authorized_by' => $actor->id,
                'can_delegate' => $canDelegate, 'delegable_permissions' => $ceiling, 'is_active' => true,
            ]);
            $this->assign($actor, $company, $responsible, $roleIds, $engagement->id);
            $this->audit($actor, $engagement, 'access.bpo.created', 'Atendimento BPO autorizado');

            return $engagement;
        });
    }

    public function endBpo(User $actor, Company $company, int $engagementId): void
    {
        DB::transaction(function () use ($actor, $company, $engagementId): void {
            $this->lock($company);
            $this->assertManager($actor, $company);
            $engagement = BpoEngagement::where('company_id', $company->id)->findOrFail($engagementId);
            $engagement->update(['is_active' => false]);
            CompanyRoleAssignment::where('bpo_engagement_id', $engagement->id)->update(['is_active' => false]);
            CompanyAccessInvitation::where('bpo_engagement_id', $engagement->id)->whereNull('accepted_at')->update(['revoked_at' => now()]);
            $this->assertAdministratorRemains($company);
            $this->audit($actor, $engagement, 'access.bpo.ended', 'Atendimento BPO encerrado');
        });
    }

    /** Returns the one-time bearer URL; only its hash is stored. */
    public function invite(User $actor, Company $company, string $email, array $roleIds, ?int $engagementId = null): string
    {
        return DB::transaction(function () use ($actor, $company, $email, $roleIds, $engagementId): string {
            $this->lock($company);
            Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:255']])->validate();
            $roles = $this->roles($company, $roleIds);
            $this->authorizeAssignment($actor, $company, $roles, $engagementId);
            throw_unless($roles->isNotEmpty(), AuthorizationException::class, 'Selecione ao menos um papel para o convite.');
            $token = Str::random(64);
            $invitation = CompanyAccessInvitation::create([
                'company_id' => $company->id, 'invited_by' => $actor->id, 'bpo_engagement_id' => $engagementId,
                'email' => mb_strtolower(trim($email)), 'role_ids' => $roles->modelKeys(),
                'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(7),
            ]);
            $this->audit($actor, $invitation, 'access.invitation.created', 'Convite para equipe criado');
            $url = route('company-access.invitation.show', ['token' => $token]);
            DB::afterCommit(fn () => Notification::route('mail', $invitation->email)
                ->notify(new CompanyTeamInvitation($company->name, $url)));

            return $url;
        });
    }

    public function accept(User $user, string $token): Company
    {
        return DB::transaction(function () use ($user, $token): Company {
            $invitation = CompanyAccessInvitation::where('token_hash', hash('sha256', $token))->firstOrFail();
            $company = $invitation->company;
            $this->lock($company);
            $invitation = CompanyAccessInvitation::whereKey($invitation->id)->lockForUpdate()->firstOrFail();
            throw_unless($user->isActive() && $company->is_active && ! $invitation->accepted_at && ! $invitation->revoked_at && $invitation->expires_at->isFuture()
                && mb_strtolower($user->email) === $invitation->email, AuthorizationException::class, 'Convite indisponível ou destinado a outro e-mail.');
            $actor = User::findOrFail($invitation->invited_by);
            // Revalidate current authority and role contents at acceptance, not only at invitation creation.
            $roles = $this->roles($company, $invitation->role_ids);
            $engagement = $this->authorizeAssignment($actor, $company, $roles, $invitation->bpo_engagement_id);
            $this->ensureMembership($user, $company, false, false);
            foreach ($roles as $role) {
                $assignment = CompanyRoleAssignment::create([
                    'company_id' => $company->id, 'user_id' => $user->id, 'company_role_id' => $role->id,
                    'granted_by' => $actor->id, 'bpo_engagement_id' => $engagement?->id, 'is_active' => true,
                ]);
                $this->audit($user, $assignment, 'access.invitation.accepted', 'Convite de acesso aceito');
            }
            $invitation->update(['accepted_at' => now()]);

            return $company;
        });
    }

    public function revokeInvitation(User $actor, Company $company, int $id): void
    {
        DB::transaction(function () use ($actor, $company, $id): void {
            $this->lock($company);
            $invitation = CompanyAccessInvitation::where('company_id', $company->id)->findOrFail($id);
            $this->authorizeAssignment($actor, $company, collect(), $invitation->bpo_engagement_id);
            $invitation->update(['revoked_at' => now()]);
            $this->audit($actor, $invitation, 'access.invitation.revoked', 'Convite revogado');
        });
    }

    public function validatePermissions(array $permissions): array
    {
        $result = [];
        foreach ($permissions as $permission => $scope) {
            Validator::make(['permission' => $permission, 'scope' => $scope], [
                'permission' => ['required', Rule::in(array_keys(PermissionCatalog::labels()))],
                'scope' => ['required', Rule::in(array_keys(PermissionCatalog::scopeOptions($permission)))],
            ])->validate();
            if ($scope !== 'none') {
                $result[$permission] = $scope;
            }
        }

        return $result;
    }

    private function authorizeAssignment(User $actor, Company $company, $roles, ?int $engagementId): ?BpoEngagement
    {
        $engagement = $engagementId ? BpoEngagement::where('company_id', $company->id)->where('is_active', true)->findOrFail($engagementId) : null;
        if ($this->access->canManageTeam($actor, $company)) {
            return $engagement;
        }
        throw_unless($actor->isActive() && $company->is_active && $engagement?->can_delegate && $engagement->responsible_id === $actor->id
            && $this->access->membership($actor, $company), AuthorizationException::class, 'Delegação não autorizada nesta empresa.');
        foreach ($roles as $role) {
            $this->assertWithinCeiling($role->permissions, $engagement->delegable_permissions);
            // The company must explicitly allow "company" to delegate record-dependent grants to another person.
            foreach ($role->permissions as $permission => $scope) {
                throw_unless(in_array('company', $this->access->scopes($actor, $company, $permission), true), AuthorizationException::class, 'Você não possui autorização ampla para delegar esta ação.');
            }
            throw_if(array_diff($role->panels, array_keys(PanelAccess::options())) !== [], AuthorizationException::class);
        }

        return $engagement;
    }

    private function assertWithinCeiling(array $permissions, array $ceiling): void
    {
        foreach ($permissions as $permission => $scope) {
            throw_unless($permission !== 'team.manage' && ($ceiling[$permission] ?? null) === 'company', AuthorizationException::class,
                'A empresa precisa autorizar alcance amplo para delegar esta ação a auxiliares.');
        }
    }

    private function roles(Company $company, array $ids)
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $roles = CompanyRole::where('company_id', $company->id)->where('is_active', true)->whereIn('id', $ids)->get();
        throw_unless(count($ids) === $roles->count(), AuthorizationException::class, 'Papel inválido para esta empresa.');

        return $roles;
    }

    private function ensureMembership(User $user, Company $company, bool $reactivate = true, bool $migrate = true): void
    {
        throw_unless($user->isActive(), AuthorizationException::class, 'Usuário inativo.');
        DB::table('users')->where('id', $user->id)->update(['scoped_access_enabled' => true]);
        $pivot = DB::table('company_user')->where('company_id', $company->id)->where('user_id', $user->id)->first();
        if (! $pivot) {
            $user->companies()->attach($company, ['role' => 'member', 'is_active' => true, 'access_mode' => 'roles']);
        } else {
            throw_if(! $reactivate && ! $pivot->is_active, AuthorizationException::class, 'O vínculo foi suspenso. Solicite reativação ao administrador.');
            DB::table('company_user')->where('company_id', $company->id)->where('user_id', $user->id)->update([
                'access_mode' => $migrate ? 'roles' : $pivot->access_mode, 'is_active' => true,
            ]);
        }
    }

    private function assertAdministratorRemains(Company $company): void
    {
        $members = $company->users()->where('users.is_active', true)->wherePivot('is_active', true)->get();
        throw_unless($members->contains(fn (User $user) => $this->access->canManageTeam($user, $company)), AuthorizationException::class,
            'Não é possível remover o último administrador ativo da empresa.');
    }

    private function lock(Company $company): void
    {
        Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
    }

    private function audit(User $actor, $record, string $event, string $summary): void
    {
        app(AuditRecorder::class)->recordModelEvent($record, $event, $summary, actorUserId: $actor->id);
    }
}
