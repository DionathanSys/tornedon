<?php

namespace App\Filament\Clusters\Financial\Resources\SefazDistributionDocuments\Actions;

use App\Filament\Clusters\Financial\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Models\SefazDistributionDocument;
use Filament\Actions\Action;
use Filament\Facades\Filament;

class OpenFiscalDocumentAction
{
    public static function make(): Action
    {
        return Action::make('openFiscalDocument')
            ->label('Nota de entrada')
            ->icon('heroicon-o-arrow-top-right-on-square')
            ->color('primary')
            ->visible(fn(SefazDistributionDocument $record): bool => $record->fiscal_document_id !== null)
            ->url(fn(SefazDistributionDocument $record): string => FiscalDocumentResource::getUrl('edit', [
                'record' => $record->fiscal_document_id,
                'tenant' => Filament::getTenant(),
            ]));
    }
}
