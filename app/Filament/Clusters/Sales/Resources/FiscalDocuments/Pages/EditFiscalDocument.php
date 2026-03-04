<?php

namespace App\Filament\Clusters\Sales\Resources\FiscalDocuments\Pages;

use App\Filament\Clusters\Sales\Resources\FiscalDocuments\FiscalDocumentResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditFiscalDocument extends EditRecord
{
    protected static string $resource = FiscalDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

}
