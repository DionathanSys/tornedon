<?php

namespace App\Filament\Operation\Concerns;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;

trait HasOperationActions
{
    public bool $showConfirmation = false;

    #[Locked]
    public string $confirmationOperation = '';

    public function requestOperationConfirmation(string $operation): void
    {
        abort_unless(collect($this->getOperationActions())->contains(fn ($action): bool => ($action['method'] ?? null) === $operation && ($action['confirm'] ?? false)), 403);
        $this->confirmationOperation = $operation;
        $this->showConfirmation = true;
    }

    public function confirmOperation(): void
    {
        abort_unless($this->showConfirmation && in_array($this->confirmationOperation, ['close', 'cancel'], true), 403);
        $this->{$this->confirmationOperation}();
        $this->showConfirmation = false;
    }

    public function getFooter(): ?View
    {
        return view('filament.operation.actions', ['actions' => $this->getOperationActions()]);
    }

    abstract protected function getOperationActions(): array;
}
