<?php

namespace App\Filament\Clusters\Financial\Resources\FiscalDocuments\Pages;

use App\Filament\Clusters\Financial\Resources\FiscalDocuments\Actions\ImportSefazDfeAction;
use App\Filament\Clusters\Financial\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Filament\Clusters\Financial\Resources\FiscalDocuments\Widgets\FiscalDocumentsStatsOverview;
use Filament\Actions\CreateAction;
use Filament\Pages\Concerns\ExposesTableToWidgets;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListFiscalDocuments extends ListRecords
{
    use ExposesTableToWidgets;

    protected static string $resource = FiscalDocumentResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            FiscalDocumentsStatsOverview::class,
        ];
    }

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
