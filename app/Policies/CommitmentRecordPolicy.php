<?php

namespace App\Policies;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

class CommitmentRecordPolicy extends FinancialRecordPolicy
{
    protected string $permission = 'account_receivables.access';

    public function viewAny(User $user): bool
    {
        return Filament::getCurrentPanel()?->getId() === 'admin' && parent::viewAny($user);
    }

    public function delete(User $user, Model $record): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
