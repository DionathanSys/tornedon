<?php

namespace App\Filament\Clusters\Financial\Resources\FiscalDocuments\Pages;

use App\Filament\Clusters\Financial\Resources\FiscalDocuments\Actions\ImportSefazDfeAction;
use App\Filament\Clusters\Financial\Resources\FiscalDocuments\FiscalDocumentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListFiscalDocuments extends ListRecords
{
    protected static string $resource = FiscalDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ImportSefazDfeAction::make(),
            CreateAction::make()
                ->label('Nota de Entrada')
                ->icon(Heroicon::Plus),
        ];
    }
}
