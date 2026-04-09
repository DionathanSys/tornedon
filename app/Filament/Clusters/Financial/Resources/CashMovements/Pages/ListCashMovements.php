<?php

namespace App\Filament\Clusters\Financial\Resources\CashMovements\Pages;

use App\Filament\Clusters\Financial\Resources\CashMovements\CashMovementResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCashMovements extends ListRecords
{
    protected static string $resource = CashMovementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            
        ];
    }
}
