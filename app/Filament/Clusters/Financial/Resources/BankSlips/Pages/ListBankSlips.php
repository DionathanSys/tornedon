<?php

namespace App\Filament\Clusters\Financial\Resources\BankSlips\Pages;

use App\Filament\Clusters\Financial\Resources\BankSlips\BankSlipResource;
use Filament\Resources\Pages\ListRecords;

class ListBankSlips extends ListRecords
{
    protected static string $resource = BankSlipResource::class;
}
