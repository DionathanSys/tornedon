<?php

namespace App\Filament\Clusters\Financial\Resources\CardPaymentProfiles\Pages;

use App\Filament\Clusters\Financial\Resources\CardPaymentProfiles\CardPaymentProfileResource;
use Filament\Resources\Pages\EditRecord;

class EditCardPaymentProfile extends EditRecord
{
    protected static string $resource = CardPaymentProfileResource::class;
}
