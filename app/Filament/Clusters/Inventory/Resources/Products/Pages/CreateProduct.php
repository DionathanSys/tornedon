<?php

namespace App\Filament\Clusters\Inventory\Resources\Products\Pages;

use App\Enum\Product\OriginSalePrice;
use App\Filament\Clusters\Inventory\Resources\Products\ProductResource;
use App\Notification\NotifyService as notify;
use App\Services\Product\ProductService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $tenant = Filament::getTenant();
        $data['company_id'] = $tenant->id;

        if($data['origin_sale_price'] === OriginSalePrice::FIXED->value) {
            $data['sale_price_value'] = null;
        }

        return $data;
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Produto criado com sucesso';
    }

    protected function handleRecordCreation(array $data): Model
    {
        $service = app(ProductService::class);
        $product = $service->create($data, Auth::id());

        if ($service->hasError() || $product === null) {
            notify::error(
                message: $service->getMessageUser(),
                errorCode: $service->getErrorCode()
            );
            $this->halt();
        }

        return $product;
    }
}
