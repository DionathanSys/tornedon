<?php

namespace App\Filament\Clusters\Financial\Resources\PurchaseClosings\Pages;

use App\Enum\PurchaseClosing\Status;
use App\Filament\Clusters\Financial\Resources\PurchaseClosings\PurchaseClosingResource;
use App\Notification\NotifyService as notify;
use App\Services\PurchaseClosing\PurchaseClosingService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class CreatePurchaseClosing extends CreateRecord
{
    protected static string $resource = PurchaseClosingResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['company_id'] = Filament::getTenant()->id;
        $data['status'] ??= Status::DRAFT->value;

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        Log::debug('CreatePurchaseClosing: Iniciando criação de fechamento de compra', [
            'metodo' => __METHOD__ . '@' . __LINE__,
            'data' => $data,
        ]);

        $service = app(PurchaseClosingService::class);
        $closing = $service->create($data, (int) Auth::id());

        if ($service->hasError() || $closing === null) {
            Log::error($service->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'message' => $service->getMessage(),
                'error_code' => $service->getErrorCode(),
                'errors' => $service->getErrors(),
            ]);

            notify::error(message: $service->getMessageUser(), errorCode: $service->getErrorCode());
            $this->halt();
        }

        return $closing;
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Fechamento de compra criado com sucesso';
    }
}
