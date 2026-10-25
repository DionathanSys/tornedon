<?php

namespace App\Filament\Exports;

use App\Models\Company;
use App\Services\Access\CompanyAuthorization;
use App\Services\Access\PermissionCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;

trait AuthorizesScopedExport
{
    public function __invoke(Model $record): array
    {
        $user = $this->export->user;
        $company = Company::findOrFail($record->company_id);
        $access = app(CompanyAuthorization::class);
        throw_unless($user && $user->canAccessTenant($company), AuthorizationException::class);
        if ($access->isManaged($user, $company)) {
            $module = PermissionCatalog::definition($record::class)['module'];
            throw_unless($access->allowsRecord($user, $company, $module.'.view', $record)
                && $access->allowsRecord($user, $company, $module.'.export', $record), AuthorizationException::class);
        }

        return parent::__invoke($record);
    }
}
