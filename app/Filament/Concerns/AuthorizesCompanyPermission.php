<?php

namespace App\Filament\Concerns;

use App\Models\Company;
use App\Models\User;
use App\Services\Access\CompanyAuthorization;
use Filament\Facades\Filament;

trait AuthorizesCompanyPermission
{
    protected static function rolePagePermission(): string
    {
        return 'settings.manage';
    }

    protected static function rolePageAllowed(): bool
    {
        $user = Filament::auth()->user();
        $company = Filament::getTenant();
        if (! $user instanceof User || ! $company instanceof Company || ! $user->canAccessTenant($company)) {
            return false;
        }
        $access = app(CompanyAuthorization::class);

        return ! $access->requiresRoleAuthorization($user, $company, Filament::getCurrentPanel()?->getId()) || $access->allows($user, $company, static::rolePagePermission());
    }

    public static function canAccess(): bool
    {
        return static::rolePageAllowed();
    }

    public function bootAuthorizesCompanyPermission(): void
    {
        abort_unless(static::rolePageAllowed(), 403);
    }
}
