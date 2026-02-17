<?php

namespace App\Filament\Clusters\Sales\Resources\Services\Pages;

use App\Filament\Clusters\Sales\Resources\Services\ServiceResource;
use App\Notification\NotifyService as notify;
use App\Services\Service\ServiceService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class CreateService extends CreateRecord
{
    protected static string $resource = ServiceResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        Log::debug('CreateService: Mutando dados antes de criar', [
            'metodo' => __METHOD__ . '@' . __LINE__,
            'data' => $data,
        ]);

        $tenant = Filament::getTenant();
        $data['company_id'] = $tenant->id;

        return $data;
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Serviço criado com sucesso';
    }

    protected function handleRecordCreation(array $data): Model
    {
        Log::debug('CreateService: Iniciando criação de serviço', [
            'metodo' => __METHOD__ . '@' . __LINE__,
            'data' => $data,
        ]);

        $service = app(ServiceService::class);
        $serviceRecord = $service->create($data, Auth::id());

        if ($service->hasError() || $serviceRecord === null) {
            Log::error('CreateService: Erro ao criar serviço', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'error_code' => $service->getErrorCode(),
                'message' => $service->getMessage(),
                'errors' => $service->getErrors(),
            ]);

            notify::error(
                message: $service->getMessageUser(),
                errorCode: $service->getErrorCode()
            );
            
            $this->halt();
        }

        Log::info('CreateService: Serviço criado com sucesso', [
            'metodo' => __METHOD__ . '@' . __LINE__,
            'service_id' => $serviceRecord->id,
        ]);

        return $serviceRecord;
    }
}
