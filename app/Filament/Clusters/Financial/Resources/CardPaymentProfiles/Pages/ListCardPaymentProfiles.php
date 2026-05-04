<?php

namespace App\Filament\Clusters\Financial\Resources\CardPaymentProfiles\Pages;

use App\Filament\Clusters\Financial\Resources\CardPaymentProfiles\CardPaymentProfileResource;
use Filament\Resources\Pages\ListRecords;

class ListCardPaymentProfiles extends ListRecords
{
    protected static string $resource = CardPaymentProfileResource::class;
}
