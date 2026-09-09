<?php

namespace App\Services\Management;

use App\Enum\User\ManagementRole;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class UserAdministrationService
{
    public function assertCanCreate(User $actor, mixed $requestedRole): void
    {
        $role = $this->resolveRole($requestedRole);

        if ($role !== null && ! $actor->isSuperAdmin()) {
            throw new AuthorizationException('Somente um superadministrador pode atribuir papéis administrativos.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function assertCanUpdate(User $actor, User $target, array $data): void
    {
        if (! $actor->canManageUsers()) {
            throw new AuthorizationException('Você não possui permissão para gerenciar usuários.');
        }

        if ($target->isSuperAdmin() && ! $actor->isSuperAdmin()) {
            throw new AuthorizationException('Somente um superadministrador pode alterar outro superadministrador.');
        }

        if ($actor->is($target) && array_key_exists('is_active', $data) && ! (bool) $data['is_active']) {
            throw new AuthorizationException('Você não pode desativar o próprio usuário.');
        }

        $roleWasChanged = array_key_exists('management_role', $data);
        $requestedRole = $roleWasChanged ? $this->resolveRole($data['management_role']) : $target->managementRole();

        if ($roleWasChanged && $requestedRole !== $target->managementRole() && ! $actor->isSuperAdmin()) {
            throw new AuthorizationException('Somente um superadministrador pode alterar papéis administrativos.');
        }

        $willBeActive = array_key_exists('is_active', $data)
            ? (bool) $data['is_active']
            : (bool) $target->is_active;
        $willBeSuperAdmin = $requestedRole === ManagementRole::SUPER_ADMIN;

        if ($target->isSuperAdmin() && (! $willBeActive || ! $willBeSuperAdmin) && ! $this->hasAnotherActiveSuperAdmin($target)) {
            throw new AuthorizationException('Não é possível remover ou desativar o último superadministrador ativo.');
        }
    }

    public function canDelete(User $actor, User $target): bool
    {
        if (! $actor->isSuperAdmin() || $actor->is($target)) {
            return false;
        }

        if ($target->isSuperAdmin() && ! $this->hasAnotherActiveSuperAdmin($target)) {
            return false;
        }

        return true;
    }

    private function hasAnotherActiveSuperAdmin(User $target): bool
    {
        return User::query()
            ->whereKeyNot($target->getKey())
            ->where('is_active', true)
            ->where(function ($query): void {
                $query
                    ->where('management_role', ManagementRole::SUPER_ADMIN->value)
                    ->orWhere(function ($legacyQuery): void {
                        $legacyQuery
                            ->whereNull('management_role')
                            ->where('is_admin', true);
                    });
            })
            ->exists();
    }

    private function resolveRole(mixed $role): ?ManagementRole
    {
        if ($role instanceof ManagementRole) {
            return $role;
        }

        if (blank($role)) {
            return null;
        }

        $resolvedRole = ManagementRole::tryFrom((string) $role);

        if ($resolvedRole === null) {
            throw new AuthorizationException('O papel administrativo informado é inválido.');
        }

        return $resolvedRole;
    }
}
