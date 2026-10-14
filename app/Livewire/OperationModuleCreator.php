<?php

namespace App\Livewire;

use App\Filament\Operation\Concerns\HasModuleForm;
use App\Filament\Operation\Concerns\HasOperationFeedback;
use App\Filament\Operation\OperationModules;
use App\Filament\Operation\OperationResourceSupport as Support;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class OperationModuleCreator extends Component
{
    use HasModuleForm, HasOperationFeedback;

    public bool $showModal = false;

    #[Locked]
    public string $resourceModule = 'production_requests';

    public function moduleKey(): string
    {
        return $this->resourceModule;
    }

    #[On('operation-create-module')]
    public function open(string $kind): void
    {
        Support::definition($kind);
        abort_unless(OperationModules::allows($kind), 403);
        $this->resourceModule = $kind;
        $this->resetValidation();
        $this->initializeModuleForm();
        $this->showModal = true;
    }

    public function create(): void
    {
        abort_unless($this->showModal && OperationModules::allows($this->resourceModule), 403);
        $service = app(Support::definition($this->resourceModule)['service']);
        $method = $this->resourceModule === 'cash_movements' ? 'createManual' : 'create';
        $record = $service->{$method}($this->moduleFormPayload(), auth()->id());
        if ($service->hasError() || ! $record) {
            $this->serviceFailed($service);

            return;
        }
        $this->showModal = false;
        $this->success($service->getMessage() ?: 'Registro criado com sucesso.');
        $this->redirect(Support::resource($this->resourceModule)::getUrl('edit', ['record' => $record]), navigate: true);
    }

    public function render(): mixed
    {
        return view('livewire.operation-module-creator');
    }
}
