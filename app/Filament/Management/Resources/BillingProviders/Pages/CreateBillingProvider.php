<?php

namespace App\Filament\Management\Resources\BillingProviders\Pages;

use App\Filament\Management\Resources\BillingProviders\BillingProviderResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBillingProvider extends CreateRecord
{
    protected static string $resource = BillingProviderResource::class;
}
