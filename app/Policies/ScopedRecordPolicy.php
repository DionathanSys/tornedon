<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Fallback for resources which previously had no policy. Existing domain policies are retained. */
class ScopedRecordPolicy
{
    public function viewAny(User $user): bool
    {
        // Gate::before resolves the model and enforces its scoped permission.
        return $user->isActive();
    }

    public function view(User $user, Model $record): bool
    {
        return $user->isActive() && $user->belongsToCompany((int) $record->company_id);
    }

    public function create(User $user): bool
    {
        return $user->isActive();
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
        return $user->isActive();
    }

    public function restore(User $user, Model $record): bool
    {
        return $this->view($user, $record);
    }
}
