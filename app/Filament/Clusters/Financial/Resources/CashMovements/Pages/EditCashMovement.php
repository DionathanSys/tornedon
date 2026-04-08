<?php

namespace App\Filament\Clusters\Financial\Resources\CashMovements\Pages;

use App\Filament\Clusters\Financial\Resources\CashMovements\CashMovementResource;
use App\Services\Financial\CashMovementService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class EditCashMovement extends EditRecord
{
    protected static string $resource = CashMovementResource::class;

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
