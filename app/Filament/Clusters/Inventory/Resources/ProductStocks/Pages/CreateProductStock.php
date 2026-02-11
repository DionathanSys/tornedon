<?php

namespace App\Filament\Clusters\Inventory\Resources\ProductStocks\Pages;

use App\Filament\Clusters\Inventory\Resources\ProductStocks\ProductStockResource;
use App\Notification\NotifyService as notify;
use App\Services\ProductStock\ProductStockService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class CreateProductStock extends CreateRecord
{
    protected static string $resource = ProductStockResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $tenant = Filament::getTenant();
        $data['company_id'] = $tenant->id;

        return $data;
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Estoque criado com sucesso';
    }

    protected function handleRecordCreation(array $data): Model
    {
        $service = app(ProductStockService::class);
        $productStock = $service->create($data, Auth::id());

        if ($service->hasError() || $productStock === null) {
            notify::error(
                message: $service->getMessageUser(),
                errorCode: $service->getErrorCode()
            );
            $this->halt();
        }

        return $productStock;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
