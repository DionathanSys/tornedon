<?php

namespace App\Filament\Bpo\Resources\FiscalDocuments\Pages;

use App\Filament\Bpo\Resources\IncomingFiscalDocumentResource;

class ListIncomingDocuments extends ListOutgoingDocuments
{
    protected static string $resource = IncomingFiscalDocumentResource::class;
}
