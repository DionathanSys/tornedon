<?php

namespace App\Filament\Shop\Resources\AccountPayables\Pages;

use App\Filament\Shop\Resources\AccountPayables\AccountPayableResource;
use App\Notification\NotifyService as notify;
use App\Services\AccountPayable\AccountPayableService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
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
                    ->hiddenLabel()
                    ->tooltip('Voltar')
                    ->icon(Heroicon::ArrowUturnLeft)
                    ->url(AccountPayableResource::getUrl()),
                DeleteAction::make()
                    ->using(function (Model $record): bool {
                        $service = app(AccountPayableService::class);
                        $result = $service->delete($record);

                        if ($service->hasError()) {
                            Log::error('Shop EditAccountPayable: Erro ao deletar conta a pagar', [
                                'metodo' => __METHOD__.'@'.__LINE__,
                                'error_code' => $service->getErrorCode(),
                                'message' => $service->getMessage(),
                                'account_payable_id' => $record->id,
                            ]);

                            notify::error(
                                message: $service->getMessageUser(),
                                errorCode: $service->getErrorCode(),
                            );

                            return false;
                        }

                        return $result;
                    }),
            ])->buttonGroup(),
        ];
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
