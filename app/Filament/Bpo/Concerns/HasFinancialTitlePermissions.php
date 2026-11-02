<?php

namespace App\Filament\Bpo\Concerns;

use App\Services\Access\CompanyAuthorization;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

trait HasFinancialTitlePermissions
{
    private ?array $titlePermissions = null;

    public function canOperateTitle(string $ability, ?Model $record = null): bool
    {
        $access = app(CompanyAuthorization::class);
        $this->titlePermissions ??= $access->effectivePermissions(Filament::auth()->user(), Filament::getTenant());
        $scopes = $this->titlePermissions[static::getResource()::$module.'.'.$ability] ?? [];

        return $record ? (int) $record->company_id === (int) Filament::getTenant()->id
            && $access->recordMatchesScopes($record, Filament::auth()->user(), $scopes) : $scopes !== [];
    }
}
