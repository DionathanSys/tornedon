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
        return view('filament.operation.actions', [
            'actions' => $this->cachedOperationActions,
        ]);
    }

    abstract protected function getOperationActions(): array;
}
