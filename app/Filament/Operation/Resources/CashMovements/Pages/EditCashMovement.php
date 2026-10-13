<?php

namespace App\Filament\Operation\Resources\CashMovements\Pages;

use App\Filament\Operation\Concerns\HasOperationResourcePage;
use App\Filament\Operation\Resources\CashMovements\CashMovementResource;

class EditCashMovement extends \App\Filament\Clusters\Financial\Resources\CashMovements\Pages\EditCashMovement
{
    use HasOperationResourcePage;

    protected static string $resource = CashMovementResource::class;

    protected string $view = 'filament.operation.resources.cash-movements.pages.mobile-edit';

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getListUrl(): string
    {
        return CashMovementResource::getUrl();
    }
}
