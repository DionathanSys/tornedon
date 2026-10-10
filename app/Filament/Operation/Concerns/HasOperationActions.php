<?php

namespace App\Filament\Operation\Concerns;

use Illuminate\Contracts\View\View;

trait HasOperationActions
{
    protected array $cachedOperationActions = [];

    public function cacheHasOperationActions(): void
    {
        foreach ($this->getOperationActions() as $action) {
            $this->cachedOperationActions[] = $this->cacheAction($action);
        }
    }

    public function getFooter(): ?View
    {
        if ($this->hasFloatingOperationAction()) {
            return view('filament.operation.floating-action', [
                'action' => $this->cachedOperationActions[0]
                    ->icon('heroicon-o-plus')->iconButton()
                    ->tooltip($this->cachedOperationActions[0]->getLabel())
                    ->extraAttributes(['class' => 'op-fab']),
            ]);
        }

        return view('filament.operation.actions', [
            'actions' => $this->cachedOperationActions,
        ]);
    }

    protected function hasFloatingOperationAction(): bool
    {
        return false;
    }

    abstract protected function getOperationActions(): array;
}
