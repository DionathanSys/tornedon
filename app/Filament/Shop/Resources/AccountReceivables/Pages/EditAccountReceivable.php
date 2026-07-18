<?php

namespace App\Filament\Shop\Resources\AccountReceivables\Pages;

use App\Filament\Shop\Resources\AccountReceivables\AccountReceivableResource;
use App\Notification\NotifyService as notify;
use App\Services\AccountReceivable\AccountReceivableService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class EditAccountReceivable extends EditRecord
{
    protected static string $resource = AccountReceivableResource::class;

    protected string $view = 'filament.shop.resources.account-receivables.pages.mobile-edit';

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getListUrl(): string
    {
        return AccountReceivableResource::getUrl();
    }

    public function deleteRecord(): void
    {
        $service = app(AccountReceivableService::class);
        $result = $service->delete($this->record);

        if ($service->hasError() || ! $result) {
            Log::error('Shop EditAccountReceivable: Erro ao deletar conta a receber', [
                'metodo' => __METHOD__.'@'.__LINE__,
                'error_code' => $service->getErrorCode(),
                'message' => $service->getMessage(),
                'account_receivable_id' => $this->record->id,
            ]);

            notify::error(
                message: $service->getMessageUser(),
                errorCode: $service->getErrorCode(),
            );

            return;
        }

        notify::success('Conta a receber excluída com sucesso.');
        $this->redirect(AccountReceivableResource::getUrl(), navigate: true);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $service = app(AccountReceivableService::class);
        $updated = $service->update($record, $data, Auth::id());

        if ($service->hasError() || $updated === null) {
            Log::error($service->getMessage(), [
                'metodo' => __METHOD__.'@'.__LINE__,
                'error_code' => $service->getErrorCode(),
                'message' => $service->getMessage(),
                'errors' => $service->getErrors(),
                'account_receivable_id' => $record->id,
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
        return 'Conta a receber atualizada com sucesso';
    }
}
