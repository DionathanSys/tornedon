<?php

namespace App\Filament\Bpo\Resources\CashMovements\Pages;

use App\Filament\Bpo\Resources\CashMovementResource;
use App\Filament\Clusters\Financial\Resources\CashMovements\Pages\ViewCashMovement;

class ViewMovement extends ViewCashMovement
{
    protected static string $resource = CashMovementResource::class;

    protected static string $layout = 'components.bpo.layout';
}
