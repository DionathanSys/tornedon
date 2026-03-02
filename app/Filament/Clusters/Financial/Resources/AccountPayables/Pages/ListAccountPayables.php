<?php

namespace App\Filament\Clusters\Financial\Resources\AccountPayables\Pages;

use App\Filament\Clusters\Financial\Resources\AccountPayables\AccountPayableResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListAccountPayables extends ListRecords
{
    protected static string $resource = AccountPayableResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Conta a Pagar')
                ->icon(Heroicon::Plus),
        ];
    }
}
