<?php

namespace App\Filament\Bpo\Resources;

use App\Filament\Bpo\Resources\FiscalDocuments\Pages;
use App\Filament\Clusters\Sales\Resources\FiscalDocuments\FiscalDocumentResource;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;

class OutgoingFiscalDocumentResource extends FiscalDocumentResource
{
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', Filament::getTenant()->id);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListOutgoingDocuments::route('/'), 'create' => Pages\CreateOutgoingDocument::route('/create'), 'edit' => Pages\EditOutgoingDocument::route('/{record}/edit')];
    }
}
