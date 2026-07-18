<?php

namespace App\Filament\Shop\Resources\CashMovements\Pages;

use App\Filament\Shop\Resources\CashMovements\CashMovementResource;

class EditCashMovement extends \App\Filament\Clusters\Financial\Resources\CashMovements\Pages\EditCashMovement
{
    protected static string $resource = CashMovementResource::class;

    protected string $view = 'filament.shop.resources.cash-movements.pages.mobile-edit';

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getListUrl(): string
    {
        return CashMovementResource::getUrl();
    }
}
