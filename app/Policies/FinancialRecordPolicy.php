<?php

namespace App\Policies;

use App\Models\User;
use App\Services\Access\UserAccessService;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

abstract class FinancialRecordPolicy
{
    protected string $permission;

    public function viewAny(User $user): bool
    {
        $tenant = Filament::getTenant();

        return $tenant && app(UserAccessService::class)->allows(
            $user, $tenant, $this->permission, Filament::getCurrentPanel()?->getId(),
        );
    }

    public function view(User $user, Model $record): bool
    {
        return (int) $record->company_id === (int) Filament::getTenant()?->id && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Model $record): bool
    {
        return $this->view($user, $record);
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->view($user, $record);
    }

    public function deleteAny(User $user): bool
    {
        return $this->viewAny($user);
    }
}
