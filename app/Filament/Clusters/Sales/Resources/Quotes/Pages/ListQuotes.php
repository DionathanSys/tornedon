<?php

namespace App\Filament\Clusters\Sales\Resources\Quotes\Pages;

use App\Filament\Clusters\Partners\Resources\CompanyPartners\CompanyPartnerResource;
use App\Filament\Clusters\Sales\Resources\Quotes\QuoteResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;

class ListQuotes extends ListRecords
{
    protected static string $resource = QuoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('new-partner')
                ->label('Novo Parceiro')
                ->icon(Heroicon::Plus)
                ->size(Size::ExtraSmall)
                ->url(CompanyPartnerResource::getUrl('create')),
        ];
    }
}
