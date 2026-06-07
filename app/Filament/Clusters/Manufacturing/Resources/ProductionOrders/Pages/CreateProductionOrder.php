<?php

namespace App\Filament\Clusters\Manufacturing\Resources\ProductionOrders\Pages;

use App\Enum\ProductionOrder\Status;
use App\Filament\Clusters\Manufacturing\Resources\ProductionOrders\ProductionOrderResource;
use App\Notification\NotifyService as notify;
use App\Services\ProductionOrder\ProductionOrderService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class CreateProductionOrder extends CreateRecord
{
    protected static string $resource = ProductionOrderResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['company_id'] = Filament::getTenant()->id;
        $data['created_by'] = Auth::id();
        $data['status'] = Status::QUEUED->value;
        
        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        $service = app(ProductionOrderService::class);
        $productionOrder = $service->create($data, Auth::id());

        if ($service->hasError() || $productionOrder === null) {
            Log::error('CreateProductionOrder: Falha ao criar ordem de produção', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'message' => $service->getMessage(),
                'error_code' => $service->getErrorCode(),
                'errors' => $service->getErrors(),
            ]);

            notify::error(
                message: $service->getMessageUser(),
                errorCode: $service->getErrorCode(),
            );

            $this->halt();
        }

        return $productionOrder;
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
