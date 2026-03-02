<?php

namespace App\Filament\Clusters\Financial\Resources\AccountReceivables\Pages;

use App\Filament\Clusters\Financial\Resources\AccountReceivables\AccountReceivableResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListAccountReceivables extends ListRecords
{
    protected static string $resource = AccountReceivableResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Conta a Receber')
                ->icon(Heroicon::Plus),
        ];
    }
}
