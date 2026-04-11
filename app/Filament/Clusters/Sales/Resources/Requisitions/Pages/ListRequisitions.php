<?php

namespace App\Filament\Clusters\Sales\Resources\Requisitions\Pages;

use App\Filament\Clusters\Partners\Resources\CompanyPartners\CompanyPartnerResource;
use App\Filament\Clusters\Sales\Resources\Requisitions\RequisitionResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;

class ListRequisitions extends ListRecords
{
    protected static string $resource = RequisitionResource::class;

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
