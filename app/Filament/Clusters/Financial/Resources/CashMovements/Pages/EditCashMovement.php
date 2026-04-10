<?php

namespace App\Filament\Clusters\Financial\Resources\CashMovements\Pages;

use App\Filament\Clusters\Financial\Resources\CashMovements\CashMovementResource;
use App\Models\CashMovement;
use App\Notification\NotifyService as notify;
use App\Services\Financial\CashMovementService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class EditCashMovement extends EditRecord
{
    protected static string $resource = CashMovementResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        if ($this->record instanceof CashMovement && $this->record->isTransfer()) {
            notify::warning(message: 'Transferencias devem ser editadas pela acao especifica no extrato financeiro.');
            $this->redirect($this->getResource()::getUrl('index'));
        }
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $service = app(CashMovementService::class);
        $movement = $service->updateManual($record, $data, Auth::id());

        if ($service->hasError() || $movement === null) {
            $this->halt();
        }

        return $movement;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
