<?php

namespace App\Filament\Clusters\Settings\Resources\AuditEntries\Pages;

use App\Filament\Clusters\Settings\Resources\AuditEntries\AuditEntryResource;
use Filament\Resources\Pages\ListRecords;

class ListAuditEntries extends ListRecords
{
    protected static string $resource = AuditEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
