<?php

namespace App\Policies;

use App\Models\User;
use App\Services\Management\UserAdministrationService;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canManageUsers();
    }

    public function view(User $user, User $target): bool
    {
        return $user->canManageUsers();
    }

    public function create(User $user): bool
    {
        return $user->canManageUsers();
    }

    public function update(User $user, User $target): bool
    {
        if (! $user->canManageUsers()) {
            return false;
        }

        return $user->isSuperAdmin() || ! $target->isSuperAdmin();
    }

    public function delete(User $user, User $target): bool
    {
        return app(UserAdministrationService::class)->canDelete($user, $target);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
