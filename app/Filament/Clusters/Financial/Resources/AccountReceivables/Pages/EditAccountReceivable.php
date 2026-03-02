<?php

namespace App\Filament\Clusters\Financial\Resources\AccountReceivables\Pages;

use App\Filament\Clusters\Financial\Resources\AccountReceivables\AccountReceivableResource;
use App\Notification\NotifyService as notify;
use App\Services\AccountReceivable\AccountReceivableService;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class EditAccountReceivable extends EditRecord
{
    protected static string $resource = AccountReceivableResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                DeleteAction::make()
                    ->using(function (Model $record): bool {
                        Log::debug('EditAccountReceivable: Iniciando exclusão de conta a receber', [
                            'metodo'                => __METHOD__ . '@' . __LINE__,
                            'account_receivable_id' => $record->id,
                        ]);

                        $service = app(AccountReceivableService::class);
                        $result = $service->delete($record);

                        if ($service->hasError()) {
                            Log::error('EditAccountReceivable: Erro ao deletar conta a receber', [
                                'metodo'                => __METHOD__ . '@' . __LINE__,
                                'error_code'             => $service->getErrorCode(),
                                'message'                => $service->getMessage(),
                                'account_receivable_id' => $record->id,
                            ]);

                            notify::error(
                                message: $service->getMessageUser(),
                                errorCode: $service->getErrorCode()
                            );
                            return false;
                        }

                        Log::info('EditAccountReceivable: Conta a receber deletada com sucesso', [
                            'metodo'                => __METHOD__ . '@' . __LINE__,
                            'account_receivable_id' => $record->id,
                        ]);

                        return $result;
                    }),
            ])->button(),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        Log::debug('EditAccountReceivable: Iniciando atualização de conta a receber', [
            'metodo'                => __METHOD__ . '@' . __LINE__,
            'account_receivable_id' => $record->id,
            'data'                  => $data,
        ]);

        $service = app(AccountReceivableService::class);
        $updated = $service->update($record, $data, Auth::id());

        if ($service->hasError() || $updated === null) {
            Log::error($service->getMessage(), [
                'metodo'                => __METHOD__ . '@' . __LINE__,
                'error_code'             => $service->getErrorCode(),
                'message'                => $service->getMessage(),
                'errors'                 => $service->getErrors(),
                'account_receivable_id' => $record->id,
            ]);

            notify::error(
                message: $service->getMessageUser(),
                errorCode: $service->getErrorCode()
            );

            $this->halt();
        }

        Log::info('EditAccountReceivable: Conta a receber atualizada com sucesso', [
            'metodo'                => __METHOD__ . '@' . __LINE__,
            'account_receivable_id' => $updated->id,
        ]);

        return $updated;
    }

    protected function getUpdatedNotificationTitle(): ?string
    {
        return 'Conta a receber atualizada com sucesso';
    }
}
