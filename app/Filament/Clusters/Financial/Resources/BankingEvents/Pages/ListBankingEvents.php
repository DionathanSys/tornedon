<?php

namespace App\Filament\Clusters\Financial\Resources\BankingEvents\Pages;

use App\Filament\Clusters\Financial\Resources\BankingEvents\BankingEventResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

class ListBankingEvents extends ListRecords
{
    protected static string $resource = BankingEventResource::class;

    public function getTabs(): array
    {
        return [
            'pending' => Tab::make('Pendências')->modifyQueryUsing(fn ($query) => $query->pending()),
            'history' => Tab::make('Histórico completo'),
        ];
    }
}
