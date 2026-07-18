<?php

namespace App\Filament\Shop\Resources\CashMovements\Pages;

use App\Filament\Shop\Resources\CashMovements\CashMovementResource;

class CreateCashMovement extends \App\Filament\Clusters\Financial\Resources\CashMovements\Pages\CreateCashMovement
{
    protected static string $resource = CashMovementResource::class;

    protected string $view = 'filament.shop.resources.cash-movements.pages.mobile-create';

    protected static ?string $title = 'Novo Movimento';

    public function getListUrl(): string
    {
        return CashMovementResource::getUrl();
    }
}
