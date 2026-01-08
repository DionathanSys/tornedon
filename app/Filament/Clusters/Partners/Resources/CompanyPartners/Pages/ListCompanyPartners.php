<?php

namespace App\Filament\Clusters\Partners\Resources\CompanyPartners\Pages;

use App\Filament\Clusters\Partners\Resources\CompanyPartners\CompanyPartnerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListCompanyPartners extends ListRecords
{
    protected static string $resource = CompanyPartnerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Parceiro')
                ->icon(Heroicon::CheckCircle),
        ];
    }
}
