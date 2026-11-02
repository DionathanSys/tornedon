<?php

namespace App\Filament\Bpo\Concerns;

use App\Services\Access\CompanyAuthorization;
use App\Services\Access\UserAccessService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

trait SecuresBpoActions
{
    private ?array $bpoActionPermissions = null;

    public function booted(): void
    {
        $this->bpoActionPermissions = null;
        Gate::authorize('viewAny', static::getResource()::getModel());
        if (method_exists($this, 'getRecord')) {
            $record = $this->getRecord();
            abort_unless((int) $record->company_id === (int) Filament::getTenant()->id, 403);
            Gate::authorize('view', $record);
        }
    }

    public function canUseBpoAction(string $permission, ?Model $record = null): bool
    {
        $access = app(CompanyAuthorization::class);
        $this->bpoActionPermissions ??= $access->effectivePermissions(Filament::auth()->user(), Filament::getTenant());
        $scopes = $this->bpoActionPermissions[$permission] ?? [];

        return $record ? (int) $record->company_id === (int) Filament::getTenant()->id
            && $access->recordMatchesScopes($record, Filament::auth()->user(), $scopes) : $scopes !== [];
    }

    protected function authorizeBpoAction(string $permission, ?Model $record = null): void
    {
        abort_unless(app(UserAccessService::class)->allows(Filament::auth()->user(), Filament::getTenant(), $permission, 'bpo'), 403);
        if ($record) {
            abort_unless((int) $record->company_id === (int) Filament::getTenant()->id, 403);
            Gate::authorize('view', $record);
            abort_unless(app(CompanyAuthorization::class)->allowsRecord(Filament::auth()->user(), Filament::getTenant(), $permission, $record), 403);
        }
    }

    protected function secureBpoAction(Action $action, string $permission): Action
    {
        return $action->authorize(fn (?Model $record = null) => $this->canUseBpoAction($permission, $record))
            ->beforeFormFilled(fn (?Model $record = null) => $this->authorizeBpoAction($permission, $record))
            ->before(fn (?Model $record = null) => $this->authorizeBpoAction($permission, $record))
            ->modalCancelActionLabel('Voltar');
    }
}
