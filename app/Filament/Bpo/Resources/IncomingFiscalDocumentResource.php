<?php

namespace App\Filament\Bpo\Resources;

use App\Filament\Bpo\Resources\FiscalDocuments\Pages;
use App\Filament\Clusters\Financial\Resources\FiscalDocuments\FiscalDocumentResource;

class IncomingFiscalDocumentResource extends FiscalDocumentResource
{
    public static function getPages(): array
    {
        return ['index' => Pages\ListIncomingDocuments::route('/'), 'create' => Pages\CreateIncomingDocument::route('/create'), 'edit' => Pages\EditIncomingDocument::route('/{record}/edit')];
    }

    public static function getWidgets(): array
    {
        return [];
    }
}
