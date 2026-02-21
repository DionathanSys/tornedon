<?php

namespace App\Filament\Clusters\Sales\Resources\Requisitions\Pages;

use App\Filament\Clusters\Sales\Resources\Requisitions\Pages\Actions\CancelRequisitionAction;
use App\Filament\Clusters\Sales\Resources\Requisitions\Pages\Actions\CloseRequisitionAction;
use App\Filament\Clusters\Sales\Resources\Requisitions\Pages\Actions\InvoiceRequisitionAction;
use App\Filament\Clusters\Sales\Resources\Requisitions\Pages\Actions\ReopenRequisitionAction;
use App\Filament\Clusters\Sales\Resources\Requisitions\RequisitionResource;
use App\Notification\NotifyService as notify;
use App\Services\Requisition\RequisitionService;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class EditRequisition extends EditRecord
{
    protected static string $resource = RequisitionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                CloseRequisitionAction::make(),
                InvoiceRequisitionAction::make(),
                CancelRequisitionAction::make(),
                ReopenRequisitionAction::make(),
                DeleteAction::make()
                    ->using(function (Model $record): bool {
                        Log::debug('EditRequisition: Iniciando soft delete de requisição', [
                            'metodo'         => __METHOD__ . '@' . __LINE__,
                            'requisition_id' => $record->id,
                        ]);

                        $service = app(RequisitionService::class);
                        $result = $service->delete($record);

                        if ($service->hasError()) {
                            Log::error('EditRequisition: Erro ao deletar requisição', [
                                'metodo'         => __METHOD__ . '@' . __LINE__,
                                'error_code'     => $service->getErrorCode(),
                                'message'        => $service->getMessage(),
                                'requisition_id' => $record->id,
                            ]);

                            notify::error(
                                message: $service->getMessageUser(),
                                errorCode: $service->getErrorCode()
                            );
                            return false;
                        }

                        Log::info('EditRequisition: Requisição deletada com sucesso', [
                            'metodo'         => __METHOD__ . '@' . __LINE__,
                            'requisition_id' => $record->id,
                        ]);

                        return $result;
                    }),
                ForceDeleteAction::make()
                    ->using(function (Model $record): bool {
                        Log::debug('EditRequisition: Iniciando force delete de requisição', [
                            'metodo'         => __METHOD__ . '@' . __LINE__,
                            'requisition_id' => $record->id,
                        ]);

                        $service = app(RequisitionService::class);
                        $result = $service->forceDelete($record);

                        if ($service->hasError()) {
                            Log::error('EditRequisition: Erro ao force delete requisição', [
                                'metodo'         => __METHOD__ . '@' . __LINE__,
                                'error_code'     => $service->getErrorCode(),
                                'message'        => $service->getMessage(),
                                'requisition_id' => $record->id,
                            ]);

                            notify::error(
                                message: $service->getMessageUser(),
                                errorCode: $service->getErrorCode()
                            );
                            return false;
                        }

                        Log::info('EditRequisition: Requisição force deleted com sucesso', [
                            'metodo'         => __METHOD__ . '@' . __LINE__,
                            'requisition_id' => $record->id,
                        ]);

                        return $result;
                    }),
                RestoreAction::make()
                    ->using(function (Model $record): bool {
                        Log::debug('EditRequisition: Iniciando restore de requisição', [
                            'metodo'         => __METHOD__ . '@' . __LINE__,
                            'requisition_id' => $record->id,
                        ]);

                        $service = app(RequisitionService::class);
                        $result = $service->restore($record);

                        if ($service->hasError()) {
                            Log::error('EditRequisition: Erro ao restore requisição', [
                                'metodo'         => __METHOD__ . '@' . __LINE__,
                                'error_code'     => $service->getErrorCode(),
                                'message'        => $service->getMessage(),
                                'requisition_id' => $record->id,
                            ]);

                            notify::error(
                                message: $service->getMessageUser(),
                                errorCode: $service->getErrorCode()
                            );
                            return false;
                        }

                        Log::info('EditRequisition: Requisição restored com sucesso', [
                            'metodo'         => __METHOD__ . '@' . __LINE__,
                            'requisition_id' => $record->id,
                        ]);

                        return $result;
                    }),
            ])->button(),
        ];
    }

    protected function resolveRecord(int|string $key): Model
    {
        return static::getModel()::withTrashed()->findOrFail($key);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        Log::debug('EditRequisition: Iniciando atualização de requisição', [
            'metodo'         => __METHOD__ . '@' . __LINE__,
            'requisition_id' => $record->id,
            'data'           => $data,
        ]);

        $service = app(RequisitionService::class);
        $updated = $service->update($record, $data, Auth::id());

        if ($service->hasError() || $updated === null) {
            Log::error($service->getMessage(), [
                'metodo'         => __METHOD__ . '@' . __LINE__,
                'error_code'     => $service->getErrorCode(),
                'message'        => $service->getMessage(),
                'errors'         => $service->getErrors(),
                'requisition_id' => $record->id,
            ]);

            notify::error(
                message: $service->getMessageUser(),
                errorCode: $service->getErrorCode()
            );

            $this->halt();
        }

        Log::info('EditRequisition: Requisição atualizada com sucesso', [
            'metodo'         => __METHOD__ . '@' . __LINE__,
            'requisition_id' => $updated->id,
        ]);

        return $updated;
    }

    protected function getUpdatedNotificationTitle(): ?string
    {
        return 'Requisição atualizada com sucesso';
    }
}
