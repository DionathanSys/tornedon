<?php

namespace App\Filament\Mobile\Resources\MobileServiceOrders\Pages;

use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\CancelServiceOrderAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\CloseServiceOrderAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\CreateServiceOrderAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\DownloadServiceOrderPdfAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\DuplicateServiceOrderAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\InvoiceServiceOrderAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\PreviewServiceOrderPdfAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\ReopenServiceOrderAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\SignServiceOrderAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\ViewInvoiceServiceOrderAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\ServiceOrderResource;
use App\Filament\Mobile\Resources\MobileServiceOrders\MobileServiceOrderResource;
use App\Filament\Mobile\Resources\MobileServiceOrders\Schemas\ServiceOrderForm;
use App\Models\CompanyPreference;
use App\Notification\NotifyService as notify;
use App\Services\ServiceOrder\ServiceOrderService;
use App\Support\ServiceOrderTravelData;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class EditMobileServiceOrder extends EditRecord
{
    protected static string $resource = MobileServiceOrderResource::class;

    public function getSubheading(): ?string
    {
        return "Ordem de Serviço # {$this->record->number} - {$this->record->status->description()}";
    }   

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                CreateServiceOrderAction::make()
                    ->color('info')
                    ->size(Size::ExtraSmall),
                $this->getSaveFormAction()
                    ->label('Salvar')
                    ->size(Size::ExtraSmall)
                    ->formId('form'),
                DuplicateServiceOrderAction::make()
                    ->hiddenLabel()
                    ->size(Size::ExtraSmall)
                    ->tooltip('Duplicar ordem de serviço'),
                PreviewServiceOrderPdfAction::make()
                    ->hiddenLabel()
                    ->size(Size::ExtraSmall)
                    ->tooltip('Preview PDF'),
                DownloadServiceOrderPdfAction::make()
                    ->color('gray')
                    ->size(Size::ExtraSmall)
                    ->hiddenLabel(),
                CloseServiceOrderAction::make()
                    ->color('gray')
                    ->size(Size::ExtraSmall)
                    ->hiddenLabel(),
                InvoiceServiceOrderAction::make()
                    ->size(Size::ExtraSmall),
                ViewInvoiceServiceOrderAction::make()
                    ->size(Size::ExtraSmall),
                CancelServiceOrderAction::make()
                    ->size(Size::ExtraSmall)
                    ->hiddenLabel(),
                ReopenServiceOrderAction::make()
                    ->size(Size::ExtraSmall)
                    ->hiddenLabel(),
                DeleteAction::make()
                    ->size(Size::ExtraSmall)
                    ->hiddenLabel()
                    ->icon(Heroicon::Trash)
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
                    ->size(Size::ExtraSmall)
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
                    ->size(Size::ExtraSmall)
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
            ])->buttonGroup()
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        Log::debug('EditServiceOrder: Mutando dados antes de salvar', [
            'metodo' => __METHOD__ . '@' . __LINE__,
            'service_order_id' => $this->record->id,
            'data' => $data,
        ]);

        unset($data['discount_amount']);
        $data['additional_info'] = ServiceOrderForm::normalizeAdditionalInfoState($data['additional_info'] ?? []);

        $currentSignature = $this->record->customer_signature;
        $newSignature = $data['customer_signature'] ?? null;

        if (blank($newSignature)) {
            $data['customer_signature'] = null;
            $data['customer_signed_at'] = null;
        } elseif ($newSignature !== $currentSignature) {
            $data['customer_signed_at'] = now();
        } else {
            $data['customer_signed_at'] = $this->record->customer_signed_at;
        }

        return $data;
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['additional_info'] = ServiceOrderForm::normalizeAdditionalInfoState($data['additional_info'] ?? []);
        $data = ServiceOrderTravelData::hydrate(
            $data,
            CompanyPreference::get('default_value_km', default: 3.5)
        );

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

        // Calcula o valor total dos itens para validação prévia
        $record->load('items');
        $totalItemsValue = $record->items->sum(function ($item) {
            return (float) $item->quantity * (float) $item->unit_price;
        });

        if ($discountAmount > $totalItemsValue) {
            notify::warning(
                message: 'O desconto não pode ser maior que o valor total dos itens (R$ ' . number_format($totalItemsValue, 2, ',', '.') . ').',
                errorCode: 'DISCOUNT_EXCEEDS_ITEMS'
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

        redirect($this->getResource()::getUrl('edit', ['record' => $record]));
    }

    /**
     * Remove todos os descontos dos itens da OS.
     */
    public function clearDiscount(): void
    {
        $record = $this->record;

        $service = app(ServiceOrderService::class);
        $result = $service->clearDiscount($record);

        if (! $result) {
            Log::error('EditServiceOrder: Erro ao remover descontos', [
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

        Log::info('EditServiceOrder: Descontos removidos com sucesso', [
            'metodo' => __METHOD__ . '@' . __LINE__,
            'service_order_id' => $record->id,
        ]);

        notify::success(
            message: 'Descontos removidos com sucesso.'
        );

        redirect($this->getResource()::getUrl('edit', ['record' => $record]));
    }


    protected function getUpdatedNotificationTitle(): ?string
    {
        return 'Ordem de serviço atualizada com sucesso';
    }
}
