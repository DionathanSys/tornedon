<?php

namespace App\Filament\Bpo\Resources\Services\Pages;

use App\Filament\Bpo\Resources\ServiceResource;

class CreateService extends \App\Filament\Clusters\Sales\Resources\Services\Pages\CreateService
{
    protected static string $resource = ServiceResource::class;

    protected static string $layout = 'components.bpo.layout';
}
