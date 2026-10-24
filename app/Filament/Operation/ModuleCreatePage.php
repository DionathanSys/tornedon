<?php

namespace App\Filament\Operation;

use App\Filament\Operation\Concerns\HasModuleForm;
use App\Filament\Operation\Concerns\HasOperationFeedback;
use App\Filament\Operation\Concerns\HasOperationResourcePage;
use Filament\Resources\Pages\Page;

abstract class ModuleCreatePage extends Page
{
    use HasModuleForm, HasOperationFeedback, HasOperationResourcePage;

    protected string $view = 'filament.operation.resources.create';

    public function moduleKey(): string
    {
        return OperationModules::forPage(static::class);
    }

    public function getTitle(): string
    {
        return OperationResourceSupport::definition($this->moduleKey())['create'];
    }

    public function mount(): void
    {
        $this->initializeModuleForm();
    }

    public function create(): void
    {
        $service = app(OperationResourceSupport::definition($this->moduleKey())['service']);
        $method = $this->moduleKey() === 'cash_movements' ? 'createManual' : 'create';
        $record = $service->{$method}($this->moduleFormPayload(), auth()->id());
        if ($service->hasError() || ! $record) {
            $this->serviceFailed($service);

            return;
        }
        $this->success($service->getMessage() ?: 'Registro criado com sucesso.');
        $this->redirect(static::getResource()::getUrl(str_starts_with($this->moduleKey(), 'account_') ? 'view' : 'edit', ['record' => $record]), navigate: true);
    }

    public function save(): void
    {
        $this->create();
    }
}
