<?php

namespace App\Filament\Clusters\Financial\Resources\AccountReceivables\Pages;

use App\Filament\Clusters\Financial\Resources\AccountReceivables\AccountReceivableResource;
use App\Notification\NotifyService as notify;
use App\Services\AccountReceivable\AccountReceivableService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class CreateAccountReceivable extends CreateRecord
{
    protected static string $resource = AccountReceivableResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $tenant = Filament::getTenant();
        $data['company_id'] = $tenant->id;

        return $data;
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Conta a receber criada com sucesso';
    }

    protected function handleRecordCreation(array $data): Model
    {
        Log::debug('CreateAccountReceivable: Iniciando criação de conta a receber', [
            'metodo' => __METHOD__ . '@' . __LINE__,
            'data'   => $data,
        ]);

        $service = app(AccountReceivableService::class);
        $accountReceivable = $service->create($data, Auth::id());

        if ($service->hasError() || $accountReceivable === null) {
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

        Log::info('CreateAccountReceivable: Conta a receber criada com sucesso', [
            'metodo'                => __METHOD__ . '@' . __LINE__,
            'account_receivable_id' => $accountReceivable->id,
        ]);

        return $accountReceivable;
    }
}
