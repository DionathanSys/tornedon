<?php

namespace App\Filament\Clusters\Inventory\Resources\ProductStocks\Pages;

use App\Filament\Clusters\Inventory\Resources\ProductStocks\ProductStockResource;
use App\Notification\NotifyService as notify;
use App\Services\ProductStock\ProductStockService;
use Filament\Actions;
use Filament\Facades\Filament as FilamentFacade;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class EditProductStock extends EditRecord
{
    protected static string $resource = ProductStockResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $data;
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Estoque atualizado com sucesso';
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $service = app(ProductStockService::class);
        $tenant = FilamentFacade::getTenant();
        $productStock = $service->update($record, $data, Auth::id(), $tenant->id);

        if ($service->hasError() || $productStock === null) {
            notify::error(
                message: $service->getMessageUser(),
                errorCode: $service->getErrorCode()
            );
            $this->halt();
        }

        return $productStock;
    }

    protected function getRedirectUrl(): ?string
    {
        return $this->getResource()::getUrl('index');
    }
}
