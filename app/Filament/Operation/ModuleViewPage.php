<?php

namespace App\Filament\Operation;

abstract class ModuleViewPage extends ModuleEditPage
{
    public function isAccountViewPage(): bool
    {
        return true;
    }

    public function save(): void
    {
        abort(403);
    }

    protected function getOperationActions(): array
    {
        $actions = parent::getOperationActions();
        if (! $this->accountHeaderLocked()) {
            $actions[] = ['label' => 'Editar dados', 'icon' => 'o-pencil-square', 'url' => static::getResource()::getUrl('edit', ['record' => $this->record])];
        }

        return $actions;
    }
}
