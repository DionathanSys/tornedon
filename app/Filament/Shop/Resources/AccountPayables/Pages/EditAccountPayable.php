<?php

namespace App\Filament\Shop\Resources\AccountPayables\Pages;

use App\Filament\Shop\Resources\AccountPayables\AccountPayableResource;
use App\Notification\NotifyService as notify;
use App\Services\AccountPayable\AccountPayableService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class EditAccountPayable extends EditRecord
{
    protected static string $resource = AccountPayableResource::class;

    protected string $view = 'filament.shop.resources.account-payables.pages.mobile-edit';

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getListUrl(): string
    {
        return AccountPayableResource::getUrl();
    }

    public function deleteRecord(): void
    {
        $service = app(AccountPayableService::class);
        $result = $service->delete($this->record);

        if ($service->hasError() || ! $result) {
            Log::error('Shop EditAccountPayable: Erro ao deletar conta a pagar', [
                'metodo' => __METHOD__.'@'.__LINE__,
                'error_code' => $service->getErrorCode(),
                'message' => $service->getMessage(),
                'account_payable_id' => $this->record->id,
            ]);

            notify::error(
                message: $service->getMessageUser(),
                errorCode: $service->getErrorCode(),
            );

            return;
        }

        notify::success('Conta a pagar excluída com sucesso.');
        $this->redirect(AccountPayableResource::getUrl(), navigate: true);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $service = app(AccountPayableService::class);
        $updated = $service->update($record, $data, Auth::id());

        if ($service->hasError() || $updated === null) {
            Log::error($service->getMessage(), [
                'metodo' => __METHOD__.'@'.__LINE__,
                'error_code' => $service->getErrorCode(),
                'message' => $service->getMessage(),
                'errors' => $service->getErrors(),
                'account_payable_id' => $record->id,
            ]);

            notify::error(
                message: $service->getMessageUser(),
                errorCode: $service->getErrorCode(),
            );

            $this->halt();
        }

        return $updated;
    }

    protected function getUpdatedNotificationTitle(): ?string
    {
        return 'Conta a pagar atualizada com sucesso';
    }
}
