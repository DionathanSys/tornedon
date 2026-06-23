<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages;

use App\Enum\ServiceOrder\State;
use App\Filament\Clusters\Sales\Resources\FiscalDocuments\FiscalDocumentResource as SalesFiscalDocumentResource;
use App\Filament\Clusters\Sales\Resources\Requisitions\RequisitionResource;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\CancelServiceOrderAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\CloseServiceOrderAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\CreateServiceOrderAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\DownloadServiceOrderPdfAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\DuplicateServiceOrderAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\GenerateRepairReturnFiscalDocumentAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\InvoiceServiceOrderAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\OpenWarrantyClaimAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\PreviewServiceOrderPdfAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\ReopenServiceOrderAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\ViewInvoiceServiceOrderAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Schemas\ServiceOrderForm;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\ServiceOrderResource;
use App\Models\CompanyPreference;
use App\Notification\NotifyService as notify;
use App\Services\ServiceOrder\ServiceOrderService;
use App\Support\ServiceOrderTravelData;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class EditServiceOrder extends EditRecord
{
    protected static string $resource = ServiceOrderResource::class;

    public function getSubheading(): ?string
    {
        return "Ordem de Serviço # {$this->record->number} - {$this->record->status->description()}";
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('back')
                    ->hiddenLabel()
                    ->tooltip('Voltar')
                    ->icon(Heroicon::ArrowUturnLeft)
                    ->url(ServiceOrderResource::getUrl()),
                CreateServiceOrderAction::make(),
                $this->getSaveFormAction()
                    ->formId('form')
                    ->hiddenLabel()
                    ->icon(Heroicon::Bookmark),
            ])->buttonGroup(),
            ActionGroup::make([
                DuplicateServiceOrderAction::make()
                    ->hiddenLabel()
                    ->tooltip('Duplicar ordem de serviço'),
                PreviewServiceOrderPdfAction::make()
                    ->hiddenLabel()
                    ->tooltip('Preview PDF'),
                DownloadServiceOrderPdfAction::make()
                    ->color('gray')
                    ->hiddenLabel(),
                OpenWarrantyClaimAction::make()
                    ->hiddenLabel(),
                GenerateRepairReturnFiscalDocumentAction::make()
                    ->hiddenLabel(),
                Action::make('view-linked-return-fiscal-document')
                    ->tooltip('Abrir nota de retorno')
                    ->label('Abrir Nota de Retorno')
                    ->icon(Heroicon::DocumentText)
                    ->visible(fn (): bool => $this->record->linkedReturnFiscalDocument() !== null)
                    ->url(fn (): ?string => ($linkedRecord = $this->record->linkedReturnFiscalDocument())
                        ? SalesFiscalDocumentResource::getUrl('edit', ['record' => $linkedRecord])
                        : null)
                    ->openUrlInNewTab(),
                CloseServiceOrderAction::make()
                    ->color('gray')
                    ->hiddenLabel(),
                InvoiceServiceOrderAction::make(),
                Action::make('view-linked-requisition')
                    ->tooltip('Abrir requisição')
                    ->label('Acessar Requisição')
                    ->icon(Heroicon::ClipboardDocumentList)
                    ->visible(fn (): bool => $this->record->requisition()->exists())
                    ->url(fn (): string => RequisitionResource::getUrl('edit', ['record' => $this->record->requisition]))
                    ->openUrlInNewTab(),
                ViewInvoiceServiceOrderAction::make(),
                CancelServiceOrderAction::make()
                    ->hiddenLabel(),
                ReopenServiceOrderAction::make()
                    ->hiddenLabel(),
                DeleteAction::make()
                    ->hiddenLabel()
                    ->icon(Heroicon::Trash)
                    ->visible(fn (Model $record): bool => blank($record->invoice_id) && ! $record->requisition()->exists())
                    ->using(function (Model $record): bool {

                        $service = app(ServiceOrderService::class);
                        $result = $service->delete($record);

                        if ($service->hasError()) {
                            Log::error('EditServiceOrder: Erro ao deletar ordem de serviço', [
                                'metodo' => __METHOD__.'@'.__LINE__,
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
                            'metodo' => __METHOD__.'@'.__LINE__,
                            'service_order_id' => $record->id,
                        ]);

                        return $result;
                    }),
            ])->button(),
        ];
    }

    protected function getFormActions(): array
    {
        if ($this->record->status === State::OPEN) {
            return [
                ...parent::getFormActions(),
            ];
        }

        return [];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        Log::debug('EditServiceOrder: Mutando dados antes de salvar', [
            'metodo' => __METHOD__.'@'.__LINE__,
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
            'metodo' => __METHOD__.'@'.__LINE__,
            'service_order_id' => $record->id,
            'data' => $data,
        ]);

        $service = app(ServiceOrderService::class);
        $updatedServiceOrder = $service->update($record, $data, Auth::id());

        if ($service->hasError() || $updatedServiceOrder === null) {
            Log::error($service->getMessage(), [
                'metodo' => __METHOD__.'@'.__LINE__,
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
            'metodo' => __METHOD__.'@'.__LINE__,
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
                message: 'O desconto não pode ser maior que o valor total dos itens (R$ '.number_format($totalItemsValue, 2, ',', '.').').',
                errorCode: 'DISCOUNT_EXCEEDS_ITEMS'
            );

            return;
        }

        $service = app(ServiceOrderService::class);
        $result = $service->applyDiscount($record, $discountAmount);

        if (! $result) {
            Log::error('EditServiceOrder: Erro ao aplicar desconto', [
                'metodo' => __METHOD__.'@'.__LINE__,
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
            'metodo' => __METHOD__.'@'.__LINE__,
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
                'metodo' => __METHOD__.'@'.__LINE__,
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
            'metodo' => __METHOD__.'@'.__LINE__,
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
