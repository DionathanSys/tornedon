<?php

namespace App\Filament\Shop\Resources\ProductionRequests\Widgets;

use App\Filament\Shop\Resources\ProductionRequests\ProductionRequestResource;
use Filament\Widgets\Widget;

class ProductionRequestOverview extends Widget
{
    protected static bool $isDiscovered = false;

    protected string $view = 'filament.shop.resources.production-requests.widgets.production-request-overview';

    protected int | string | array $columnSpan = 'full';

    protected function getViewData(): array
    {
        return [
            'createUrl' => ProductionRequestResource::getUrl('create'),
        ];
    }
}
