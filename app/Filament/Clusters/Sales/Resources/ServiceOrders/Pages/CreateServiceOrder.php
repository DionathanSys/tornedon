<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages;

use App\Filament\Clusters\Sales\Resources\ServiceOrders\ServiceOrderResource;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Schemas\ServiceOrderForm;
use App\Notification\NotifyService as notify;
use App\Services\ServiceOrder\ServiceOrderService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Enum\ServiceOrder\State;
use App\Enum\ServiceOrder\Priority;

class CreateServiceOrder extends CreateRecord
{
    protected static string $resource = ServiceOrderResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $tenant             = Filament::getTenant();
        $data['company_id'] = $tenant->id;
        $data['status']     = State::OPEN;
        $data['priority']   = Priority::NORMAL;

        unset($data['discount_amount']);
        $data['additional_info'] = ServiceOrderForm::normalizeAdditionalInfoState($data['additional_info'] ?? []);

        if (filled($data['customer_signature'] ?? null)) {
            $data['customer_signed_at'] = now();
        } else {
            $data['customer_signed_at'] = null;
        }

        return $data;
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Ordem de serviço criada com sucesso';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit');
    }

    protected function handleRecordCreation(array $data): Model
    {
        $service = app(ServiceOrderService::class);
        $serviceOrder = $service->create($data, Auth::id());

        if ($service->hasError() || $serviceOrder === null) {
            Log::error($service->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'message' => $service->getMessage(),
                'error_code' => $service->getErrorCode(),
                'errors' => $service->getErrors(),
            ]);

            notify::error(
                message: $service->getMessageUser(),
                errorCode: $service->getErrorCode()
            );

            $this->halt();
        }

        Log::info('CreateServiceOrder: Ordem de serviço criada com sucesso', [
            'metodo' => __METHOD__ . '@' . __LINE__,
            'service_order_id' => $serviceOrder->id,
        ]);

        return $serviceOrder;
    }
}
