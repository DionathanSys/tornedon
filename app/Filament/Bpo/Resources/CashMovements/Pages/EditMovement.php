<?php

namespace App\Filament\Bpo\Resources\CashMovements\Pages;

use App\Filament\Bpo\Resources\CashMovementResource;
use App\Filament\Clusters\Financial\Resources\CashMovements\Pages\EditCashMovement;

class EditMovement extends EditCashMovement
{
    protected static string $layout = 'components.bpo.layout';

    protected static string $resource = CashMovementResource::class;
}
