<?php

namespace App\Services\Access;

use App\Models\AccountPayableInstallmentPayment;
use App\Models\AccountReceivableInstallmentPayment;
use App\Models\CashMovement;
use App\Models\Company;
use App\Models\FiscalDocument;
use App\Models\User;
use App\Policies\ScopedRecordPolicy;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class RoleAccessEnforcement
{
    public function __construct(private CompanyAuthorization $access) {}

    public function register(): void
    {
        foreach ([...array_column(PermissionCatalog::modules(), 'model'), ...array_keys(PermissionCatalog::children())] as $class) {
            if (! Gate::getPolicyFor($class)) {
                Gate::policy($class, ScopedRecordPolicy::class);
            }
            $class::addGlobalScope(new RoleRecordScope);
            $class::creating(fn (Model $record) => $this->creating($record));
            $class::updating(fn (Model $record) => $this->writing($record, 'update'));
            $class::deleting(fn (Model $record) => $this->writing($record, 'delete'));
        }
        Gate::before(fn (User $user, string $ability, array $arguments) => $this->gate($user, $ability, $arguments));
    }

    private function gate(User $user, string $ability, array $arguments): ?bool
    {
        $subject = $arguments[0] ?? null;
        $companyId = $subject instanceof Model ? $subject->getAttribute('company_id') : null;
        $company = $subject instanceof Company ? $subject : ($companyId ? Company::find($companyId) : Filament::getTenant());
        if ($company instanceof Company && $this->access->isScopedUser($user) && ! $user->canAccessTenant($company)) {
            return false;
        }
        if (! $company instanceof Company || ! $this->access->isManaged($user, $company)) {
            return null;
        }
        if ($subject instanceof Company || $subject === Company::class) {
            return match ($ability) {
                'view', 'viewAny' => $user->canAccessTenant($company) ? null : false,
                'update' => $this->access->allows($user, $company, 'company.update') ? null : false,
                default => false,
            };
        }
        if ($subject === User::class || $subject instanceof User) {
            return false; // Company managers never receive access to platform user administration.
        }
        $class = is_string($subject) ? $subject : ($subject instanceof Model ? $subject::class : null);
        if (! $class || ! is_subclass_of($class, Model::class)) {
            return null;
        }
        $action = $this->action($ability);
        [$record, $definition] = $this->resolveSubject($subject);
        if (! $definition) {
            return $this->access->allows($user, $company, 'settings.manage') ? null : false;
        }
        $permission = $definition['module'].'.'.$action;
        $allowed = $record
            ? $this->access->allowsRecord($user, $company, $permission, $record)
            : $this->access->allows($user, $company, $permission);

        return $allowed ? null : false; // Existing policies still enforce lifecycle and financial invariants.
    }

    public function creating(Model $record): void
    {
        $user = Auth::user();
        if (! $user) {
            return;
        }
        [$parent, $definition] = $this->resolveSubject($record);
        $company = Company::find($record->getAttribute('company_id') ?? $parent?->getAttribute('company_id'));
        if ($this->access->isScopedUser($user)) {
            throw_unless($company && $user->canAccessTenant($company), AuthorizationException::class);
        }
        if (! $user || ! $company || ! $this->access->isManaged($user, $company)) {
            return;
        }
        throw_unless($definition && ($parent === $record
            ? $this->access->allows($user, $company, $definition['module'].'.create')
            : $parent && $this->access->allowsRecord($user, $company, $definition['module'].'.'.$this->childAction($record, 'create'), $parent)), AuthorizationException::class);
        if ($parent !== $record && $record->getAttribute('company_id') !== null) {
            throw_unless((int) $record->company_id === (int) $parent->company_id, AuthorizationException::class);
        }
        if ($record instanceof CashMovement && $record->getAttribute('reversal_of_id')) {
            $original = CashMovement::find($record->getAttribute('reversal_of_id'));
            throw_unless($original && $this->access->allowsRecord($user, $company, 'cash_movements.reverse', $original), AuthorizationException::class);
        }
        if ($parent === $record) {
            if ($definition['owner']) {
                $record->setAttribute($definition['owner'], $user->id);
            }
            $this->validateAssignees($record, $definition, $user, $company);
        }
    }

    public function writing(Model $record, string $action): void
    {
        $user = Auth::user();
        if (! $user) {
            return;
        }
        [$parent, $definition] = $this->resolveSubject($record);
        $company = Company::find($record->getRawOriginal('company_id') ?? $parent?->getAttribute('company_id'));
        if ($this->access->isScopedUser($user)) {
            throw_unless($company && $user->canAccessTenant($company), AuthorizationException::class);
        }
        if (! $user || ! $company || ! $this->access->isManaged($user, $company)) {
            return;
        }
        $permissionAction = $parent === $record ? $action : $this->childAction($record, $action);
        throw_unless($parent && $definition && $this->access->allowsRecord($user, $company, $definition['module'].'.'.$permissionAction, $parent), AuthorizationException::class);
        throw_if($record->isDirty('company_id') || ($parent === $record && $definition['owner'] && $record->isDirty($definition['owner'])), AuthorizationException::class, 'Empresa e autoria não podem ser alteradas.');
        $child = PermissionCatalog::children()[$record::class] ?? null;
        if ($child) {
            $relation = $record->{explode('.', $child[0])[0]}();
            throw_if($record->isDirty($relation->getForeignKeyName()), AuthorizationException::class, 'O vínculo com o documento de origem não pode ser alterado.');
            if ($record->isDirty('ignored_at')) {
                throw_unless($this->access->allowsRecord($user, $company, $definition['module'].'.change_status_without_movement', $parent), AuthorizationException::class);
            }
        }
        if ($parent === $record && $action === 'update') {
            foreach ($this->transitionActions($record) as $transition) {
                throw_unless($this->access->allowsRecord($user, $company, $definition['module'].'.'.$transition, $parent), AuthorizationException::class, 'Sem permissão para esta transição.');
            }
        }
        $this->validateAssignees($record, $definition, $user, $company);
    }

    private function validateAssignees(Model $record, array $definition, User $user, Company $company): void
    {
        foreach ($definition['assigned'] as $column) {
            if ($record->isDirty($column)) {
                throw_unless($this->access->allows($user, $company, $definition['module'].'.assign'), AuthorizationException::class, 'Você não pode alterar responsáveis.');
                if ($record->getAttribute($column)) {
                    $assignee = User::find($record->getAttribute($column));
                    throw_unless($assignee && $assignee->canAccessTenant($company), AuthorizationException::class, 'Responsável sem vínculo ativo nesta empresa.');
                }
            }
        }
    }

    private function resolveSubject(mixed $subject): array
    {
        $class = is_string($subject) ? $subject : $subject::class;
        $child = PermissionCatalog::children()[$class] ?? null;
        if ($child) {
            $source = $subject instanceof Model
                ? ($subject->exists ? $subject->newFromBuilder($subject->getRawOriginal()) : clone $subject)
                : $subject;
            $record = $source instanceof Model ? data_get($source, $child[0]) : null;

            return [$record, PermissionCatalog::definition($child[1])];
        }

        return [$subject instanceof Model ? $subject : null, PermissionCatalog::definition($class)];
    }

    private function childAction(Model $record, string $action): string
    {
        if ($record instanceof AccountPayableInstallmentPayment || $record instanceof AccountReceivableInstallmentPayment) {
            return $action === 'delete' ? 'reverse' : 'settle';
        }

        return 'update';
    }

    private function transitionActions(Model $record): array
    {
        $actions = [];
        $status = $record->getAttribute('status');
        $status = $status instanceof \BackedEnum ? $status->value : $status;
        if ($record->isDirty('status') && in_array($status, ['canceled', 'cancelled', 'cancelada', 'cancelado'], true)) {
            $actions[] = 'cancel';
        }
        if (($record->isDirty('approved_at') && $record->getAttribute('approved_at'))
            || ($record->isDirty('confirmed') && $record->getAttribute('confirmed'))) {
            $actions[] = 'approve';
        }
        if ($record instanceof FiscalDocument && $record->isDirty('emission_requested_at') && $record->getAttribute('emission_requested_at')) {
            $actions[] = 'issue';
        }

        return $actions;
    }

    private function action(string $ability): string
    {
        return match ($ability) {
            'viewAny', 'view' => 'view',
            'deleteAny', 'forceDelete', 'forceDeleteAny' => 'delete',
            'restore', 'restoreAny', 'replicate', 'reorder', 'modifyPricing', 'close' => 'update',
            'invoice', 'emit' => 'issue',
            default => $ability,
        };
    }
}
