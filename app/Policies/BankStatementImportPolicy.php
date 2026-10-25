<?php

namespace App\Policies;

use App\Models\User;
use App\Services\Access\UserAccessService;
use Filament\Facades\Filament;

class BankStatementImportPolicy extends CashMovementPolicy
{
    public function viewAny(User $user): bool
    {
        if (Filament::getCurrentPanel()?->getId() === 'bpo') {
            $company = Filament::getTenant();

            return $company && app(UserAccessService::class)->allows($user, $company, 'bank_statements.view', 'bpo');
        }

        return parent::viewAny($user);
    }
}
