<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages;

use App\Filament\Clusters\Sales\Resources\ServiceOrders\ServiceOrderResource;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\CancelServiceOrderAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\CloseServiceOrderAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\InvoiceServiceOrderAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\ReopenServiceOrderAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\ViewInvoiceServiceOrderAction;
use App\Notification\NotifyService as notify;
use App\Services\ServiceOrder\ServiceOrderService;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class EditServiceOrder extends EditRecord
{
    protected static string $resource = ServiceOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                CloseServiceOrderAction::make(),
                InvoiceServiceOrderAction::make(),
                ViewInvoiceServiceOrderAction::make(),
                CancelServiceOrderAction::make(),
                ReopenServiceOrderAction::make(),
                DeleteAction::make()
                    ->using(function (Model $record): bool {
                        Log::debug('EditServiceOrder: Iniciando soft delete de ordem de serviço', [
                            'metodo' => __METHOD__ . '@' . __LINE__,
                            'service_order_id' => $record->id,
                        ]);

                        $service = app(ServiceOrderService::class);
                        $result = $service->delete($record);

                        if ($service->hasError()) {
                            Log::error('EditServiceOrder: Erro ao deletar ordem de serviço', [
                                'metodo' => __METHOD__ . '@' . __LINE__,
                                'error_code' => $service->getErrorCode(),
                                'message' => $service->getMessage(),
                                'service_order_id' => $record->id,
                            ]);

                            notify::error(
                                message: $service->getMessageUser(),
                                errorCode: $service->getErrorCode()
                            );
                            return false;
                        }

                        Log::info('EditServiceOrder: Ordem de serviço deletada com sucesso', [
                            'metodo' => __METHOD__ . '@' . __LINE__,
                            'service_order_id' => $record->id,
                        ]);

                        return $result;
                    }),
                ForceDeleteAction::make()
                    ->using(function (Model $record): bool {
                        Log::debug('EditServiceOrder: Iniciando force delete de ordem de serviço', [
                            'metodo' => __METHOD__ . '@' . __LINE__,
                            'service_order_id' => $record->id,
                        ]);

                        $service = app(ServiceOrderService::class);
                        $result = $service->forceDelete($record);

                        if ($service->hasError()) {
                            Log::error('EditServiceOrder: Erro ao force delete ordem de serviço', [
                                'metodo' => __METHOD__ . '@' . __LINE__,
                                'error_code' => $service->getErrorCode(),
                                'message' => $service->getMessage(),
                                'service_order_id' => $record->id,
                            ]);

                            notify::error(
                                message: $service->getMessageUser(),
                                errorCode: $service->getErrorCode()
                            );
                            return false;
                        }

                        Log::info('EditServiceOrder: Ordem de serviço force deleted com sucesso', [
                            'metodo' => __METHOD__ . '@' . __LINE__,
                            'service_order_id' => $record->id,
                        ]);

                        return $result;
                    }),
                RestoreAction::make()
                    ->using(function (Model $record): bool {
                        Log::debug('EditServiceOrder: Iniciando restore de ordem de serviço', [
                            'metodo' => __METHOD__ . '@' . __LINE__,
                            'service_order_id' => $record->id,
                        ]);

                        $service = app(ServiceOrderService::class);
                        $result = $service->restore($record);

                        if ($service->hasError()) {
                            Log::error('EditServiceOrder: Erro ao restore ordem de serviço', [
                                'metodo' => __METHOD__ . '@' . __LINE__,
                                'error_code' => $service->getErrorCode(),
                                'message' => $service->getMessage(),
                                'service_order_id' => $record->id,
                            ]);

                            notify::error(
                                message: $service->getMessageUser(),
                                errorCode: $service->getErrorCode()
                            );
                            return false;
                        }

                        Log::info('EditServiceOrder: Ordem de serviço restored com sucesso', [
                            'metodo' => __METHOD__ . '@' . __LINE__,
                            'service_order_id' => $record->id,
                        ]);

                        return $result;
                    }),
            ])->button()
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        Log::debug('EditServiceOrder: Mutando dados antes de salvar', [
            'metodo' => __METHOD__ . '@' . __LINE__,
            'service_order_id' => $this->record->id,
            'data' => $data,
        ]);

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        Log::debug('EditServiceOrder: Iniciando atualização de ordem de serviço', [
            'metodo' => __METHOD__ . '@' . __LINE__,
            'service_order_id' => $record->id,
            'data' => $data,
        ]);

        $service = app(ServiceOrderService::class);
        $updatedServiceOrder = $service->update($record, $data, Auth::id());

        if ($service->hasError() || $updatedServiceOrder === null) {
            Log::error($service->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'error_code' => $service->getErrorCode(),
                'message' => $service->getMessage(),
                'errors' => $service->getErrors(),
                'service_order_id' => $record->id,
            ]);

            notify::error(
                message: $service->getMessageUser(),
                errorCode: $service->getErrorCode()
            );

            $this->halt();
        }

        Log::info('EditServiceOrder: Ordem de serviço atualizada com sucesso', [
            'metodo' => __METHOD__ . '@' . __LINE__,
            'service_order_id' => $updatedServiceOrder->id,
        ]);

        return $updatedServiceOrder;
    }

    /**
     * Aplica o desconto igualmente entre os itens da OS.
     */
    public function applyDiscount(): void
    {
        $record = $this->record;
        $discountAmount = (float) str_replace(['.', ','], ['', '.'], $this->data['discount_amount'] ?? '0');

        if ($discountAmount <= 0) {
            notify::warning(
                message: 'Informe um valor de desconto maior que zero.',
                errorCode: 'DISCOUNT_INVALID'
            );
            return;
        }

        $service = app(ServiceOrderService::class);
        $result = $service->applyDiscount($record, $discountAmount);

        if (! $result) {
            Log::error('EditServiceOrder: Erro ao aplicar desconto', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'message' => $service->getMessage(),
                'service_order_id' => $record->id,
            ]);

            notify::error(
                message: $service->getMessageUser(),
                errorCode: $service->getErrorCode()
            );
            return;
        }

        Log::info('EditServiceOrder: Desconto aplicado com sucesso', [
            'metodo' => __METHOD__ . '@' . __LINE__,
            'service_order_id' => $record->id,
            'discount_amount' => $discountAmount,
        ]);

        notify::success(
            message: 'Desconto aplicado com sucesso aos itens.'
        );

        // Recarrega os itens para refletir as mudanças
        $this->record->refresh();
        $this->fillForm();
    }

    protected function getUpdatedNotificationTitle(): ?string
    {
        return 'Ordem de serviço atualizada com sucesso';
    }
}
