<?php

namespace App\Filament\Clusters\Manufacturing\Resources\ProductionOrders\Pages;

use App\Enum\ProductionOrder\DestinationType;
use App\Enum\ProductionOrder\Status;
use App\Filament\Clusters\Financial\Resources\Invoices\InvoiceResource;
use App\Filament\Clusters\Manufacturing\Resources\ProductionOrders\Pages\Actions\DownloadProductionOrderPdfAction;
use App\Filament\Clusters\Manufacturing\Resources\ProductionOrders\Pages\Actions\InvoiceProductionOrderAction;
use App\Filament\Clusters\Manufacturing\Resources\ProductionOrders\Pages\Actions\PreviewProductionOrderPdfAction;
use App\Filament\Clusters\Manufacturing\Resources\ProductionOrders\ProductionOrderResource;
use App\Filament\Clusters\Sales\Resources\Requisitions\RequisitionResource;
use App\Notification\NotifyService as notify;
use App\Services\ProductionOrder\ProductionOrderService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Checkbox;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class EditProductionOrder extends EditRecord
{
    protected static string $resource = ProductionOrderResource::class;

    public function getSubheading(): ?string
    {
        return 'OP # '.($this->record->production_order_number ?? $this->record->id)
            .' - '.$this->record->status->description();
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('back')
                    ->hiddenLabel()
                    ->tooltip('Voltar')
                    ->icon(Heroicon::ArrowUturnLeft)
                    ->url(ProductionOrderResource::getUrl()),
                $this->getSaveFormAction()
                    ->formId('form')
                    ->hiddenLabel()
                    ->icon(Heroicon::Bookmark),
            ])->buttonGroup(),
            ActionGroup::make([
                Action::make('closeProductionOrder')
                    ->label('Encerrar Ordem de Produção')
                    ->icon(Heroicon::CheckCircle)
                    ->color('success')
                    ->visible(fn (): bool => in_array($this->record->status, [Status::QUEUED, Status::IN_PROGRESS, Status::QC_CHECK], true))
                    ->requiresConfirmation()
                    ->schema([
                        Checkbox::make('invoice_after_finalize')
                            ->label('Faturar ao finalizar')
                            ->visible(fn (): bool => $this->shouldShowInvoiceAfterFinalizeOption())
                            ->helperText('Uso direto: gera a requisição, encerra para reservar e, se marcado, fatura e abre a fatura.')
                            ->default(false),
                    ])
                    ->action(function (array $data): void {
                        if (! $this->record->items()->exists()) {
                            notify::warning('Adicione pelo menos um item antes de encerrar a ordem.');

                            return;
                        }

                        $this->record->items()->get()->each(function ($item): void {
                            $quantity = (float) $item->quantity;

                            $item->update([
                                'quantity_produced' => $quantity,
                                'quantity_approved' => $quantity,
                                'quantity_rejected' => 0,
                            ]);
                        });

                        if ($this->record->status !== Status::QC_CHECK) {
                            $this->record->update([
                                'status' => Status::QC_CHECK->value,
                                'started_at' => $this->record->started_at ?? now(),
                                'updated_by' => Auth::id(),
                            ]);

                            $this->record->refresh();
                        }

                        $service = app(ProductionOrderService::class);
                        $result = $service->finalize(
                            $this->record,
                            Auth::id(),
                            (bool) ($data['invoice_after_finalize'] ?? false),
                        );

                        if ($service->hasError() || $result === null) {
                            $this->notifyServiceError($service, 'EditProductionOrder: Erro ao finalizar produção');

                            return;
                        }

                        $this->record->refresh();
                        $invoice = $result['invoice'] ?? null;

                        if ($invoice) {
                            notify::success('Produção finalizada e faturada com sucesso.');
                            $this->redirect(InvoiceResource::getUrl('edit', ['record' => $invoice]));

                            return;
                        }

                        notify::success(
                            $this->resolvedDestinationType() === DestinationType::DIRECT_DELIVERY
                                ? 'Produção finalizada e reservada para venda com sucesso.'
                                : 'Produção finalizada com entrada em estoque registrada.'
                        );
                    }),
                Action::make('viewRequisition')
                    ->label('Abrir Requisição')
                    ->icon(Heroicon::ClipboardDocumentList)
                    ->visible(fn (): bool => filled($this->record->requisition_id))
                    ->url(fn (): ?string => $this->record->requisition_id
                        ? RequisitionResource::getUrl('edit', ['record' => $this->record->requisition_id])
                        : null)
                    ->openUrlInNewTab(),
                InvoiceProductionOrderAction::make(),
                Action::make('viewInvoice')
                    ->label('Abrir Fatura')
                    ->icon(Heroicon::DocumentText)
                    ->visible(fn (): bool => filled($this->record->invoice_id))
                    ->url(fn (): ?string => $this->record->invoice_id
                        ? InvoiceResource::getUrl('edit', ['record' => $this->record->invoice_id])
                        : null)
                    ->openUrlInNewTab(),
                PreviewProductionOrderPdfAction::make(),
                DownloadProductionOrderPdfAction::make(),
                Action::make('cancelProduction')
                    ->label('Cancelar')
                    ->icon(Heroicon::NoSymbol)
                    ->color('danger')
                    ->visible(fn (): bool => in_array($this->record->status, [Status::QUEUED, Status::IN_PROGRESS, Status::QC_CHECK], true))
                    ->requiresConfirmation()
                    ->action(function (): void {
                        $service = app(ProductionOrderService::class);

                        if (! $service->cancel($this->record, Auth::id())) {
                            $this->notifyServiceError($service, 'EditProductionOrder: Erro ao cancelar ordem');

                            return;
                        }

                        $this->record->refresh();
                        notify::success('Ordem de produção cancelada.');
                    }),
                DeleteAction::make()
                    ->visible(fn (): bool => blank($this->record->invoice_id) && blank($this->record->requisition_id)),
            ])->button(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['updated_by'] = Auth::id();
        $data['destination_type'] = filled($data['customer_id'] ?? null)
            ? DestinationType::DIRECT_DELIVERY->value
            : DestinationType::STOCK->value;

        return $data;
    }

    protected function getRedirectUrl(): ?string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }

    private function shouldShowInvoiceAfterFinalizeOption(): bool
    {
        return $this->resolvedDestinationType() === DestinationType::DIRECT_DELIVERY;
    }

    private function resolvedDestinationType(): DestinationType
    {
        return filled($this->record->customer_id)
            ? DestinationType::DIRECT_DELIVERY
            : DestinationType::STOCK;
    }

    private function notifyServiceError(ProductionOrderService $service, string $logMessage): void
    {
        Log::error($logMessage, [
            'metodo' => __METHOD__.'@'.__LINE__,
            'production_order_id' => $this->record->id,
            'error_code' => $service->getErrorCode(),
            'message' => $service->getMessage(),
            'errors' => $service->getErrors(),
        ]);

        notify::error(
            message: $service->getMessageUser(),
            errorCode: $service->getErrorCode(),
        );
    }
}
