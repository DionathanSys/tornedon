<?php

namespace App\Filament\Operation\Resources\ProductionRequests\Widgets;

use App\Filament\Operation\Concerns\AuthorizesOperationModule;
use App\Filament\Operation\Resources\ProductionRequests\ProductionRequestResource;
use Filament\Widgets\Widget;

class ProductionRequestOverview extends Widget
{
    use AuthorizesOperationModule;

    protected static bool $isDiscovered = false;

    protected string $view = 'filament.operation.resources.production-requests.widgets.production-request-overview';

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        return [
            'createUrl' => ProductionRequestResource::getUrl('create'),
        ];
    }
}
