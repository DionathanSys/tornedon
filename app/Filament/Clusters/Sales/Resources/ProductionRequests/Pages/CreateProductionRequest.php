<?php

namespace App\Filament\Clusters\Sales\Resources\ProductionRequests\Pages;

use App\Enum\ProductionRequest\Status;
use App\Filament\Clusters\Sales\Resources\ProductionRequests\ProductionRequestResource;
use App\Notification\NotifyService as notify;
use App\Services\ProductionRequest\ProductionRequestService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class CreateProductionRequest extends CreateRecord
{
    protected static string $resource = ProductionRequestResource::class;

    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction(),
        ];
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['company_id'] = Filament::getTenant()->id;
        $data['status'] = Status::OPEN->value;
        $data['additional_info'] = $this->extractAdditionalInfo($data);

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        $service = app(ProductionRequestService::class);
        $record = $service->create($data, Auth::id());

        if ($service->hasError() || $record === null) {
            notify::error(message: $service->getMessageUser(), errorCode: $service->getErrorCode());
            $this->halt();
        }

        return $record;
    }

    private function extractAdditionalInfo(array &$data): array
    {
        $additionalInfo = $data['additional_info'] ?? [];

        $additionalInfo['card_payment_profile_id'] = $data['card_payment_profile_id'] ?? null;
        $additionalInfo['payment_date'] = $data['payment_date'] ?? null;

        unset($data['card_payment_profile_id'], $data['payment_date'], $data['is_manual_counterparty']);

        if (filled($data['customer_id'] ?? null)) {
            $data['manual_counterparty_name'] = null;
        }

        return $additionalInfo;
    }
}
