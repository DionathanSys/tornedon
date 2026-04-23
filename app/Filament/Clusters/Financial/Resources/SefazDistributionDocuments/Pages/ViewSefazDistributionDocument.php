<?php

namespace App\Filament\Clusters\Financial\Resources\SefazDistributionDocuments\Pages;

use App\Filament\Clusters\Financial\Resources\SefazDistributionDocuments\SefazDistributionDocumentResource;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ViewRecord;

class ViewSefazDistributionDocument extends ViewRecord
{
    protected static string $resource = SefazDistributionDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back_to_list')
                ->label('Voltar')
                ->url(route('filament.admin.financial.resources.sefaz-distribution-documents.index', [
                    'tenant' => Filament::getTenant(),
                ])),
        ];
    }
}
