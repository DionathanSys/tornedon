<?php

namespace App\Filament\Clusters\Financial\Resources\CardInstitutions\Pages;

use App\Filament\Clusters\Financial\Resources\CardInstitutions\CardInstitutionResource;
use Filament\Resources\Pages\ListRecords;

class ListCardInstitutions extends ListRecords
{
    protected static string $resource = CardInstitutionResource::class;
}
