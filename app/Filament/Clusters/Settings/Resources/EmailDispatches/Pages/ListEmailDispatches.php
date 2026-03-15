<?php

namespace App\Filament\Clusters\Settings\Resources\EmailDispatches\Pages;

use App\Filament\Clusters\Settings\Resources\EmailDispatches\EmailDispatchResource;
use Filament\Resources\Pages\ListRecords;

class ListEmailDispatches extends ListRecords
{
    protected static string $resource = EmailDispatchResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}

