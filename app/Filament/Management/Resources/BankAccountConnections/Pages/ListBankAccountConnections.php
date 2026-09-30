<?php

namespace App\Filament\Management\Resources\BankAccountConnections\Pages;

use App\Filament\Management\Resources\BankAccountConnections\BankAccountConnectionResource;
use Filament\Resources\Pages\ListRecords;

class ListBankAccountConnections extends ListRecords
{
    protected static string $resource = BankAccountConnectionResource::class;
}
