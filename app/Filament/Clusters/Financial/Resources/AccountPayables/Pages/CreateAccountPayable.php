<?php

namespace App\Filament\Clusters\Financial\Resources\AccountPayables\Pages;

use App\Filament\Clusters\Financial\Resources\AccountPayables\AccountPayableResource;
use App\Notification\NotifyService as notify;
use App\Services\AccountPayable\AccountPayableService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class CreateAccountPayable extends CreateRecord
{
    protected static string $resource = AccountPayableResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $tenant = Filament::getTenant();
        $data['company_id'] = $tenant->id;

        return $data;
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Conta a pagar criada com sucesso';
    }

    protected function handleRecordCreation(array $data): Model
    {
        Log::debug('CreateAccountPayable: Iniciando criação de conta a pagar', [
            'metodo' => __METHOD__ . '@' . __LINE__,
            'data'   => $data,
        ]);

        $service = app(AccountPayableService::class);
        $accountPayable = $service->create($data, Auth::id());

        if ($service->hasError() || $accountPayable === null) {
            Log::error($service->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'message'    => $service->getMessage(),
                'error_code' => $service->getErrorCode(),
                'errors'     => $service->getErrors(),
            ]);

            notify::error(
                message: $service->getMessageUser(),
                errorCode: $service->getErrorCode()
            );

            $this->halt();
        }

        Log::info('CreateAccountPayable: Conta a pagar criada com sucesso', [
            'metodo'             => __METHOD__ . '@' . __LINE__,
            'account_payable_id' => $accountPayable->id,
        ]);

        return $accountPayable;
    }
}
