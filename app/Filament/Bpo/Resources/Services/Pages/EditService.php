<?php

namespace App\Filament\Bpo\Resources\Services\Pages;

use App\Filament\Bpo\Resources\ServiceResource;
use Illuminate\Database\Eloquent\Model;

class EditService extends \App\Filament\Clusters\Sales\Resources\Services\Pages\EditService
{
    protected static string $resource = ServiceResource::class;

    protected static string $layout = 'components.bpo.layout';

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function resolveRecord(int|string $key): Model
    {
        return ServiceResource::getEloquentQuery()->findOrFail($key);
    }
}
