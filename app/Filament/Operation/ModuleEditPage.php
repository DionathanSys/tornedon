<?php

namespace App\Filament\Operation;

use App\Filament\Operation\Concerns\HasModuleForm;
use App\Filament\Operation\Concerns\HasModulePayment;
use App\Filament\Operation\Concerns\HasOperationActions;
use App\Filament\Operation\Concerns\HasOperationFeedback;
use App\Filament\Operation\Concerns\HasOperationResourcePage;
use Filament\Resources\Pages\Page;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;

abstract class ModuleEditPage extends Page
{
    use HasModuleForm, HasModulePayment, HasOperationActions, HasOperationFeedback, HasOperationResourcePage;

    protected string $view = 'filament.operation.resources.detail';

    /** @var Model */
    #[Locked]
    public $record;

    public function moduleKey(): string
    {
        return OperationModules::forPage(static::class);
    }

    public function getTitle(): string
    {
        return OperationResourceSupport::definition($this->moduleKey())['title'].' #'.$this->record->id;
    }

    public function mount(int|string $record): void
    {
        $this->record = OperationResourceSupport::query($this->moduleKey())->findOrFail($record);
        $this->initializeModuleForm($this->record);
        if ($this->moduleKey() === 'cash_movements' && $this->record->isTransfer()) {
            $this->warning('Transferências devem ser editadas pela ação específica no extrato financeiro.');
            $this->redirect(static::getResource()::getUrl(), navigate: true);
        }
    }

    public function save(): void
    {
        $this->record = OperationResourceSupport::query($this->moduleKey())->findOrFail($this->record->id);
        $service = app(OperationResourceSupport::definition($this->moduleKey())['service']);
        $method = $this->moduleKey() === 'cash_movements' ? 'updateManual' : 'update';
        $record = $service->{$method}($this->record, $this->moduleFormPayload(), auth()->id());
        if ($service->hasError() || ! $record) {
            $this->serviceFailed($service);

            return;
        }
        $this->record = $record;
        $this->initializeModuleForm($record);
        $this->success($service->getMessage() ?: 'Registro salvo com sucesso.');
    }

    public function hydrate(): void
    {
        $this->record = OperationResourceSupport::query($this->moduleKey())->findOrFail($this->record->id);
    }

    public function registerPayment(): void
    {
        $this->openRegisterPayment($this->record->id);
    }

    public function deleteRecord(): void
    {
        abort_unless($this->showConfirmation && $this->confirmationOperation === 'deleteRecord' && str_starts_with($this->moduleKey(), 'account_'), 403);
        $record = OperationResourceSupport::query($this->moduleKey())->findOrFail($this->record->id);
        $service = app(OperationResourceSupport::definition($this->moduleKey())['service']);
        if (! $service->delete($record)) {
            $this->serviceFailed($service);

            return;
        }
        $this->success('Conta excluída com sucesso.');
        $this->redirect(static::getResource()::getUrl(), navigate: true);
    }

    protected function getOperationActions(): array
    {
        $actions = [
            ['label' => 'Lista', 'icon' => 'o-arrow-left', 'url' => static::getResource()::getUrl()],
            ['label' => 'Salvar', 'icon' => 'o-check', 'method' => 'save', 'primary' => true],
        ];
        if (str_starts_with($this->moduleKey(), 'account_')) {
            if (in_array($this->record->status->value, OperationResourceSupport::definition($this->moduleKey())['pending'], true)) {
                $actions[] = ['label' => $this->moduleKey() === 'account_receivables' ? 'Receber' : 'Pagar', 'icon' => 'o-banknotes', 'method' => 'registerPayment'];
            }
            $actions[] = ['label' => 'Excluir conta', 'icon' => 'o-trash', 'method' => 'deleteRecord', 'more' => true, 'confirm' => true];
        }

        return $actions;
    }
}
