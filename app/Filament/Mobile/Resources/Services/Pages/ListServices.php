<?php

namespace App\Filament\Mobile\Resources\Services\Pages;

use App\Filament\Clusters\Sales\Resources\Services\Pages\ListServices as BaseListServices;
use App\Filament\Mobile\Resources\Services\ServiceResource;

class ListServices extends BaseListServices
{
    protected static string $resource = ServiceResource::class;
}
