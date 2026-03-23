<?php

namespace App\Filament\Mobile\Resources\Services\Pages;

use App\Filament\Clusters\Sales\Resources\Services\Pages\CreateService as BaseCreateService;
use App\Filament\Mobile\Resources\Services\ServiceResource;

class CreateService extends BaseCreateService
{
    protected static string $resource = ServiceResource::class;
}
