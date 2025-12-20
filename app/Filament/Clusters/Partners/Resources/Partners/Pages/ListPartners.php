<?php

namespace App\Filament\Clusters\Partners\Resources\Partners\Pages;

use App\Filament\Clusters\Partners\Resources\Partners\PartnerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListPartners extends ListRecords
{
    protected static string $resource = PartnerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Parceiro')
                ->icon(Heroicon::PlusCircle),
        ];
    }
}
