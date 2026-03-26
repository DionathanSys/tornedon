<?php

namespace App\Filament\Clusters\Sales\Resources\Services\Pages;

use App\Filament\Clusters\Sales\Resources\Services\ServiceResource;
use App\Notification\NotifyService as notify;
use App\Services\Service\ServiceService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class EditService extends EditRecord
{
    protected static string $resource = ServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('new-service')
                ->label('Serviço')
                ->url(ServiceResource::getUrl('create'))
                ->icon(Heroicon::Plus)
                ->color('primary')
                ->size(Size::Small),
            DeleteAction::make()
                ->size(Size::Small)
                ->using(function (Model $record): bool {
                    Log::debug('EditService: Iniciando soft delete de serviço', [
                        'metodo' => __METHOD__ . '@' . __LINE__,
                        'service_id' => $record->id,
                    ]);

                    $service = app(ServiceService::class);
                    $result = $service->delete($record);

                    if ($service->hasError()) {
                        Log::error('EditService: Erro ao deletar serviço', [
                            'metodo' => __METHOD__ . '@' . __LINE__,
                            'error_code' => $service->getErrorCode(),
                            'message' => $service->getMessage(),
                            'service_id' => $record->id,
                        ]);

                        notify::error(
                            message: $service->getMessageUser(),
                            errorCode: $service->getErrorCode()
                        );
                        return false;
                    }

                    Log::info('EditService: Serviço deletado com sucesso', [
                        'metodo' => __METHOD__ . '@' . __LINE__,
                        'service_id' => $record->id,
                    ]);

                    return $result;
                }),
            ForceDeleteAction::make()
                ->size(Size::Small)
                ->using(function (Model $record): bool {
                    Log::debug('EditService: Iniciando force delete de serviço', [
                        'metodo' => __METHOD__ . '@' . __LINE__,
                        'service_id' => $record->id,
                    ]);

                    $service = app(ServiceService::class);
                    $result = $service->forceDelete($record);

                    if ($service->hasError()) {
                        Log::error('EditService: Erro ao force delete serviço', [
                            'metodo' => __METHOD__ . '@' . __LINE__,
                            'error_code' => $service->getErrorCode(),
                            'message' => $service->getMessage(),
                            'service_id' => $record->id,
                        ]);

                        notify::error(
                            message: $service->getMessageUser(),
                            errorCode: $service->getErrorCode()
                        );
                        return false;
                    }

                    Log::info('EditService: Serviço force deleted com sucesso', [
                        'metodo' => __METHOD__ . '@' . __LINE__,
                        'service_id' => $record->id,
                    ]);

                    return $result;
                }),
                ->size(Size::Small)
            RestoreAction::make()
                ->using(function (Model $record): bool {
                    Log::debug('EditService: Iniciando restore de serviço', [
                        'metodo' => __METHOD__ . '@' . __LINE__,
                        'service_id' => $record->id,
                    ]);

                    $service = app(ServiceService::class);
                    $result = $service->restore($record);

                    if ($service->hasError()) {
                        Log::error('EditService: Erro ao restore serviço', [
                            'metodo' => __METHOD__ . '@' . __LINE__,
                            'error_code' => $service->getErrorCode(),
                            'message' => $service->getMessage(),
                            'service_id' => $record->id,
                        ]);

                        notify::error(
                            message: $service->getMessageUser(),
                            errorCode: $service->getErrorCode()
                        );
                        return false;
                    }

                    Log::info('EditService: Serviço restored com sucesso', [
                        'metodo' => __METHOD__ . '@' . __LINE__,
                        'service_id' => $record->id,
                    ]);

                    return $result;
                }),
        ];
    }

    protected function resolveRecord(int|string $key): Model
    {
        return static::getModel()::withTrashed()->findOrFail($key);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        Log::debug('EditService: Mutando dados antes de salvar', [
            'metodo' => __METHOD__ . '@' . __LINE__,
            'service_id' => $this->record->id,
            'data' => $data,
        ]);

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        Log::debug('EditService: Iniciando atualização de serviço', [
            'metodo' => __METHOD__ . '@' . __LINE__,
            'service_id' => $record->id,
            'data' => $data,
        ]);

        $service = app(ServiceService::class);
        $updatedService = $service->update($record, $data, Auth::id());

        if ($service->hasError() || $updatedService === null) {
            Log::error('EditService: Erro ao atualizar serviço', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'error_code' => $service->getErrorCode(),
                'message' => $service->getMessage(),
                'errors' => $service->getErrors(),
                'service_id' => $record->id,
            ]);

            notify::error(
                message: $service->getMessageUser(),
                errorCode: $service->getErrorCode()
            );

            $this->halt();
        }

        Log::info('EditService: Serviço atualizado com sucesso', [
            'metodo' => __METHOD__ . '@' . __LINE__,
            'service_id' => $updatedService->id,
        ]);

        return $updatedService;
    }

    protected function getUpdatedNotificationTitle(): ?string
    {
        return 'Serviço atualizado com sucesso';
    }
}
