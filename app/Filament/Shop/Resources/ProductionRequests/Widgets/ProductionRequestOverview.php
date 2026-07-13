<?php

namespace App\Filament\Shop\Resources\ProductionRequests\Widgets;

use App\Enum\ProductionRequest\Status;
use App\Filament\Shop\Resources\ProductionRequests\ProductionRequestResource;
use App\Models\ProductionRequest;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

class ProductionRequestOverview extends Widget
{
    protected static bool $isDiscovered = false;

    protected string $view = 'filament.shop.resources.production-requests.widgets.production-request-overview';

    protected int | string | array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $tenantId = Filament::getTenant()?->getKey();

        return [
            'createUrl' => ProductionRequestResource::getUrl('create'),
            'openCount' => $tenantId
                ? ProductionRequest::query()
                    ->where('company_id', $tenantId)
                    ->where('status', Status::OPEN->value)
                    ->count()
                : 0,
        ];
    }
}
