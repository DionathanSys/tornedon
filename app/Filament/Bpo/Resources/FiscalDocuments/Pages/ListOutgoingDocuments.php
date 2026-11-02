<?php

namespace App\Filament\Bpo\Resources\FiscalDocuments\Pages;

use App\Filament\Bpo\Pages\FiscalDocuments;
use App\Filament\Bpo\Resources\OutgoingFiscalDocumentResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;

class ListOutgoingDocuments extends ListRecords
{
    protected static string $resource = OutgoingFiscalDocumentResource::class;

    public function mount(): void
    {
        parent::mount();
        $this->redirect(FiscalDocuments::getUrl(panel: 'bpo', tenant: Filament::getTenant()));
    }
}
