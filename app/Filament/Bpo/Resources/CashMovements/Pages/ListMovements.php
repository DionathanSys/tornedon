<?php

namespace App\Filament\Bpo\Resources\CashMovements\Pages;

use App\Filament\Bpo\Resources\CashMovementResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMovements extends ListRecords
{
    protected static string $layout = 'components.bpo.layout';

    protected static string $resource = CashMovementResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Novo movimento manual')];
    }
}
