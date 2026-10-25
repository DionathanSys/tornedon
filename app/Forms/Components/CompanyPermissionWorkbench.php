<?php

namespace App\Forms\Components;

use App\Services\Access\PermissionCatalog;
use Closure;
use Filament\Forms\Components\Field;

class CompanyPermissionWorkbench extends Field
{
    protected string $view = 'forms.components.company-permission-workbench';

    protected bool $delegationMode = false;

    protected array|Closure|null $availablePermissions = null;

    public function delegation(bool $condition = true): static
    {
        $this->delegationMode = $condition;

        return $this;
    }

    public function availablePermissions(array|Closure|null $permissions): static
    {
        $this->availablePermissions = $permissions;

        return $this;
    }

    public function isDelegation(): bool
    {
        return $this->delegationMode;
    }

    public function getPermissionGroups(): array
    {
        $available = $this->evaluate($this->availablePermissions);

        return collect(PermissionCatalog::editorGroups())->map(function (array $group) use ($available): array {
            $group['permissions'] = array_values(array_filter($group['permissions'], fn ($permission) => (! $this->delegationMode || $permission['key'] !== 'team.manage')
                && ($available === null || in_array($permission['key'], $available, true))));
            if ($this->delegationMode) {
                foreach ($group['permissions'] as &$permission) {
                    $permission['options'] = ['none' => 'Não delegar', 'company' => 'Pode delegar'];
                }
                unset($permission);
            }

            return $group;
        })->filter(fn ($group) => $group['permissions'] !== [])->values()->all();
    }
}
