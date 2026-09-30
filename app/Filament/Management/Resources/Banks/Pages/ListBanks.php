<?php

namespace App\Filament\Management\Resources\Banks\Pages;

use App\Filament\Management\Resources\Banks\BankResource;
use Filament\Resources\Pages\ListRecords;

class ListBanks extends ListRecords
{
    protected static string $resource = BankResource::class;
}
