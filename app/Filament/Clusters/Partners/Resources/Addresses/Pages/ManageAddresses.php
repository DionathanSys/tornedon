<?php

namespace App\Filament\Clusters\Partners\Resources\Addresses\Pages;

use App\Filament\Clusters\Partners\Resources\Addresses\Actions\CreateAddressAction;
use App\Filament\Clusters\Partners\Resources\Addresses\AddressResource;
use App\Services\Address\AddressService;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use App\Notification\NotifyService as notify;
use App\Services\Partner\CompanyPartnerService;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

class ManageAddresses extends ManageRecords
{
    protected static string $resource = AddressResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAddressAction::make(),
        ];
    }
}
