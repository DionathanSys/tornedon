<?php

namespace App\Filament\Operation\Resources\CashMovements\Pages;

use App\Filament\Operation\Concerns\HasOperationResourcePage;
use App\Filament\Operation\Resources\CashMovements\CashMovementResource;

class CreateCashMovement extends \App\Filament\Clusters\Financial\Resources\CashMovements\Pages\CreateCashMovement
{
    use HasOperationResourcePage;

    protected static string $resource = CashMovementResource::class;

    protected string $view = 'filament.operation.resources.cash-movements.pages.mobile-create';

    protected static ?string $title = 'Novo Movimento';

    public function getListUrl(): string
    {
        return CashMovementResource::getUrl();
    }
}
