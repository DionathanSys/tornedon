<?php

namespace App\Filament\Clusters\Financial\Resources\CashMovements\Pages;

use App\Filament\Clusters\Financial\Resources\CashMovements\CashMovementResource;
use App\Filament\Clusters\Financial\Resources\CashMovements\Schemas\CashMovementInfolist;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;

class ViewCashMovement extends ViewRecord
{
    protected static string $resource = CashMovementResource::class;

    public function infolist(Schema $schema): Schema
    {
        return CashMovementInfolist::configure($schema);
    }
}
