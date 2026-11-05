<?php

namespace App\Filament\Bpo\Resources\Services\Pages;

use App\Filament\Bpo\Pages\Sales;
use App\Filament\Bpo\Resources\ServiceResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListServices extends ListRecords
{
    protected static string $resource = ServiceResource::class;

    protected static string $layout = 'components.bpo.layout';

    protected function getHeaderActions(): array
    {
        return [Action::make('sales')->label('Vendas')->url(Sales::getUrl())->visible(fn () => Sales::canAccess()), CreateAction::make()->label('Novo serviço')];
    }
}
