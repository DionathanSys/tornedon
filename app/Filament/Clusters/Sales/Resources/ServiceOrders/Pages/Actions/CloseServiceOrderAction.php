<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions;

use App\Enum\ServiceOrder\State;
use App\Enum\Requisition\Status as RequisitionStatus;
use App\Filament\Clusters\Financial\Resources\Invoices\InvoiceResource;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\ServiceOrderResource;
use App\Models\Requisition;
use App\Models\ServiceOrder;
use App\Notification\NotifyService as notify;
use App\Services\Email\DocumentNotificationService;
use App\Services\Requisition\RequisitionService;
use App\Services\ServiceOrder\ServiceOrderService;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class CloseServiceOrderAction
{
    public static function make(): Action
    {
        return Action::make('closeServiceOrder')
            ->label('Encerrar')
            ->icon(Heroicon::CheckCircle)
            ->color('success')
            ->tooltip('Encerrar Ordem de Serviço')
            ->requiresConfirmation()
            ->modalHeading('Encerrar Ordem de Serviço')
            ->modalDescription('Tem certeza que deseja encerrar esta ordem de serviço? Esta ação mudará o status para "Encerrada".')
            ->modalSubmitActionLabel('Sim, encerrar')
            ->schema([
                Checkbox::make('send_email')
                    ->label('Enviar e-mail ao encerrar?')
                    ->default(fn(ServiceOrder $record): bool => app(DocumentNotificationService::class)->shouldSendForServiceOrder($record)),
                Checkbox::make('invoice_after_close')
                    ->label('Faturar ao encerrar')
                    ->helperText('Se marcado, a ordem de serviço será faturada logo após o encerramento.')
                    ->live()
                    ->disabled(fn (Get $get): bool => (bool) $get('invoice_linked_requisition'))
                    ->default(false),
                Checkbox::make('close_linked_requisition')
                    ->label('Encerrar Requisição')
                    ->helperText('Encerrar também a requisição vinculada a esta ordem de serviço.')
                    ->visible(fn (ServiceOrder $record): bool => $record->requisition?->status === RequisitionStatus::OPEN)
                    ->live(),
                Checkbox::make('invoice_linked_requisition')
                    ->label('Faturar Requisição')
                    ->helperText('Inclui a requisição vinculada na mesma fatura gerada para a ordem de serviço.')
                    ->visible(fn (ServiceOrder $record): bool => in_array($record->requisition?->status, [
                        RequisitionStatus::OPEN,
                        RequisitionStatus::CLOSED,
                    ], true))
                    ->live()
                    ->afterStateUpdated(function (Set $set, bool $state): void {
                        if (! $state) {
                            return;
                        }

                        $set('close_linked_requisition', true);
                        $set('invoice_after_close', true);
                    }),
            ])
            ->visible(fn(ServiceOrder $record): bool => $record->status === State::OPEN)
            ->action(function (ServiceOrder $record, array $data): void {
                $shouldInvoiceAfterClose = (bool) ($data['invoice_after_close'] ?? false);
                $shouldCloseLinkedRequisition = (bool) ($data['close_linked_requisition'] ?? false);
                $shouldInvoiceLinkedRequisition = (bool) ($data['invoice_linked_requisition'] ?? false);
                $linkedRequisition = $record->requisition()->first();

                if ($shouldInvoiceLinkedRequisition) {
                    $shouldInvoiceAfterClose = true;
                    $shouldCloseLinkedRequisition = true;
                }

                Log::debug('CloseServiceOrderAction (Filament): Encerrando OS', [
                    'metodo'              => __METHOD__ . '@' . __LINE__,
                    'service_order_id'    => $record->id,
                    'user_id'             => Auth::id(),
                    'invoice_after_close' => $shouldInvoiceAfterClose,
                    'close_linked_requisition' => $shouldCloseLinkedRequisition,
                    'invoice_linked_requisition' => $shouldInvoiceLinkedRequisition,
                    'linked_requisition_id' => $linkedRequisition?->id,
                ]);

                $service = app(ServiceOrderService::class);
                $service->close($record, Auth::id(), (bool) ($data['send_email'] ?? false));

                if ($service->hasError()) {
                    Log::error('CloseServiceOrderAction (Filament): Erro ao encerrar OS', [
                        'metodo'           => __METHOD__ . '@' . __LINE__,
                        'service_order_id' => $record->id,
                        'error_code'       => $service->getErrorCode(),
                        'message'          => $service->getMessage(),
                    ]);

                    notify::error(
                        message: $service->getMessageUser(),
                        errorCode: $service->getErrorCode()
                    );

                    return;
                }

                if ($linkedRequisition instanceof Requisition && $shouldCloseLinkedRequisition && $linkedRequisition->state()->canTransitionTo('close')) {
                    $requisitionService = app(RequisitionService::class);
                    $closedRequisition = $requisitionService->close($linkedRequisition->fresh(), Auth::id());

                    if ($requisitionService->hasError() || ! $closedRequisition) {
                        Log::error('CloseServiceOrderAction (Filament): Erro ao encerrar requisição vinculada', [
                            'metodo' => __METHOD__ . '@' . __LINE__,
                            'service_order_id' => $record->id,
                            'requisition_id' => $linkedRequisition->id,
                            'error_code' => $requisitionService->getErrorCode(),
                            'message' => $requisitionService->getMessage(),
                        ]);

                        notify::error(
                            message: $requisitionService->getMessageUser(),
                            errorCode: $requisitionService->getErrorCode()
                        );

                        return;
                    }
                }

                if ($shouldInvoiceAfterClose) {
                    $invoice = $service->invoice($record->fresh(), Auth::id());

                    if ($service->hasError() || ! $invoice) {
                        Log::error('CloseServiceOrderAction (Filament): Erro ao faturar OS após encerramento', [
                            'metodo'           => __METHOD__ . '@' . __LINE__,
                            'service_order_id' => $record->id,
                            'error_code'       => $service->getErrorCode(),
                            'message'          => $service->getMessage(),
                        ]);

                        notify::error(
                            message: $service->getMessageUser(),
                            errorCode: $service->getErrorCode()
                        );

                        return;
                    }

                    if ($linkedRequisition instanceof Requisition && $shouldInvoiceLinkedRequisition) {
                        $requisitionService = app(RequisitionService::class);
                        $linkedRequisition = $linkedRequisition->fresh();
                        $invoiceWithRequisition = $requisitionService->invoiceIntoExisting($linkedRequisition, Auth::id(), $invoice);

                        if ($requisitionService->hasError() || ! $invoiceWithRequisition) {
                            Log::error('CloseServiceOrderAction (Filament): Erro ao faturar requisição na mesma fatura da OS', [
                                'metodo' => __METHOD__ . '@' . __LINE__,
                                'service_order_id' => $record->id,
                                'requisition_id' => $linkedRequisition->id,
                                'invoice_id' => $invoice->id,
                                'error_code' => $requisitionService->getErrorCode(),
                                'message' => $requisitionService->getMessage(),
                            ]);

                            notify::error(
                                message: $requisitionService->getMessageUser(),
                                errorCode: $requisitionService->getErrorCode()
                            );

                            return;
                        }
                    }

                    Log::info('CloseServiceOrderAction (Filament): OS encerrada e faturada com sucesso', [
                        'metodo'           => __METHOD__ . '@' . __LINE__,
                        'service_order_id' => $record->id,
                        'invoice_id'       => $invoice->id,
                    ]);

                    notify::success(
                        $shouldInvoiceLinkedRequisition
                            ? 'Ordem de serviço encerrada, requisição vinculada faturada e tudo incluído na mesma fatura com sucesso.'
                            : 'Ordem de serviço encerrada e faturada com sucesso.'
                    );

                    return;
                }

                Log::info('CloseServiceOrderAction (Filament): OS encerrada com sucesso', [
                    'metodo'           => __METHOD__ . '@' . __LINE__,
                    'service_order_id' => $record->id,
                ]);

                notify::success(
                    $shouldCloseLinkedRequisition && $linkedRequisition instanceof Requisition
                        ? 'Ordem de serviço e requisição vinculada encerradas com sucesso.'
                        : 'Ordem de serviço encerrada com sucesso.'
                );
            })
            ->successRedirectUrl(function (ServiceOrder $record): string {
                $record->refresh();

                if ($record->invoice_id) {
                    return InvoiceResource::getUrl('edit', ['record' => $record->invoice_id]);
                }

                return ServiceOrderResource::getUrl('edit', ['record' => $record]);
            });
    }
}
