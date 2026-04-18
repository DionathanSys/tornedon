<?php

namespace App\Filament\Clusters\Financial\Resources\AccountPayables\Pages;

use App\Filament\Clusters\Financial\Resources\AccountPayableInstallments\AccountPayableInstallmentResource;
use App\Filament\Clusters\Financial\Resources\AccountPayables\AccountPayableResource;
use App\Notification\NotifyService as notify;
use App\Services\AccountPayable\AccountPayableService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class EditAccountPayable extends EditRecord
{
    protected static string $resource = AccountPayableResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('back')
                    ->label('Voltar')
                    ->icon('heroicon-o-arrow-left')
                    ->color('gray')
                    ->url(AccountPayableInstallmentResource::getUrl()),
                DeleteAction::make()
                    ->using(function (Model $record): bool {
                        Log::debug('EditAccountPayable: Iniciando exclusão de conta a pagar', [
                            'metodo'             => __METHOD__ . '@' . __LINE__,
                            'account_payable_id' => $record->id,
                        ]);

                        $service = app(AccountPayableService::class);
                        $result = $service->delete($record);

                        if ($service->hasError()) {
                            Log::error('EditAccountPayable: Erro ao deletar conta a pagar', [
                                'metodo'             => __METHOD__ . '@' . __LINE__,
                                'error_code'         => $service->getErrorCode(),
                                'message'            => $service->getMessage(),
                                'account_payable_id' => $record->id,
                            ]);

                            notify::error(
                                message: $service->getMessageUser(),
                                errorCode: $service->getErrorCode()
                            );
                            return false;
                        }

                        Log::info('EditAccountPayable: Conta a pagar deletada com sucesso', [
                            'metodo'             => __METHOD__ . '@' . __LINE__,
                            'account_payable_id' => $record->id,
                        ]);

                        return $result;
                    }),
            ])->buttonGroup(),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        Log::debug('EditAccountPayable: Iniciando atualização de conta a pagar', [
            'metodo'             => __METHOD__ . '@' . __LINE__,
            'account_payable_id' => $record->id,
            'data'               => $data,
        ]);

        $service = app(AccountPayableService::class);
        $updated = $service->update($record, $data, Auth::id());

        if ($service->hasError() || $updated === null) {
            Log::error($service->getMessage(), [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'error_code'         => $service->getErrorCode(),
                'message'            => $service->getMessage(),
                'errors'             => $service->getErrors(),
                'account_payable_id' => $record->id,
            ]);

            notify::error(
                message: $service->getMessageUser(),
                errorCode: $service->getErrorCode()
            );

            $this->halt();
        }

        Log::info('EditAccountPayable: Conta a pagar atualizada com sucesso', [
            'metodo'             => __METHOD__ . '@' . __LINE__,
            'account_payable_id' => $updated->id,
        ]);

        return $updated;
    }

    protected function getUpdatedNotificationTitle(): ?string
    {
        return 'Conta a pagar atualizada com sucesso';
    }
}
