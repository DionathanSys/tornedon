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
        abort_unless($this->showConfirmation && collect($this->getOperationActions())->contains(fn ($action): bool => ($action['method'] ?? null) === $this->confirmationOperation && ($action['confirm'] ?? false)), 403);
        $this->{$this->confirmationOperation}();
        $this->showConfirmation = false;
    }

    public function getFooter(): ?View
    {
        return view('filament.operation.actions', ['actions' => $this->getOperationActions()]);
    }

    public function confirmationTitle(): string
    {
        return match ($this->confirmationOperation) {
            'cancel' => 'Cancelar registro?', 'close' => 'Encerrar registro?',
            default => (collect($this->getOperationActions())->first(fn ($action) => ($action['method'] ?? null) === $this->confirmationOperation)['label'] ?? 'Confirmar operação').'?',
        };
    }

    public function confirmationIsDestructive(): bool
    {
        return in_array($this->confirmationOperation, ['cancel', 'deleteRecord'], true);
    }

    abstract protected function getOperationActions(): array;
}
