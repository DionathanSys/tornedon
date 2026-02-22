<?php

namespace App\Filament\Clusters\Sales\Resources\Requisitions\Pages;

use App\Filament\Clusters\Sales\Resources\Requisitions\RequisitionResource;
use App\Services\Requisition\RequisitionService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Notification\NotifyService as notify;
use Illuminate\Database\Eloquent\Model;

class CreateRequisition extends CreateRecord
{
    protected static string $resource = RequisitionResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        Log::debug('CreateRequisition: Mutando dados antes de criar', [
            'metodo' => __METHOD__ . '@' . __LINE__,
            'data' => $data,
        ]);

        $tenant = Filament::getTenant();
        $data['company_id'] = $tenant->id;

        return $data;
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Requisição criada com sucesso';
    }

    protected function handleRecordCreation(array $data): Model
    {
        Log::debug('CreateRequisition: Iniciando criação de requisição', [
            'metodo' => __METHOD__ . '@' . __LINE__,
            'data' => $data,
        ]);

        $service = app(RequisitionService::class);
        $requisition = $service->create($data, Auth::id());

        if ($service->hasError() || $requisition === null) {
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

        Log::info('CreateRequisition: Requisição criada com sucesso', [
            'metodo' => __METHOD__ . '@' . __LINE__,
            'requisition_id' => $requisition->id,
        ]);

        return $requisition;
    }

}
