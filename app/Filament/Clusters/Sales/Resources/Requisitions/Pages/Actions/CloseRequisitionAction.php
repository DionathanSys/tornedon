<?php

namespace App\Filament\Clusters\Sales\Resources\Requisitions\Pages\Actions;

use App\Enum\Requisition\Status;
use App\Filament\Clusters\Financial\Resources\Invoices\InvoiceResource;
use App\Filament\Clusters\Sales\Resources\Requisitions\RequisitionResource;
use App\Models\Requisition;
use App\Notification\NotifyService as notify;
use App\Services\Email\DocumentNotificationService;
use App\Services\Requisition\RequisitionService;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Toggle;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class CloseRequisitionAction
{
    public static function make(): Action
    {
        return Action::make('closeRequisition')
            ->label('Encerrar')
            ->icon(Heroicon::CheckCircle)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Encerrar Requisição')
            ->modalDescription('Tem certeza que deseja encerrar esta requisição? Esta ação mudará o status para "Encerrada".')
            ->modalSubmitActionLabel('Sim, encerrar')
            ->schema([
                Checkbox::make('send_email')
                    ->label('Enviar e-mail ao encerrar?')
                    ->default(fn (Requisition $record): bool => app(DocumentNotificationService::class)->shouldSendForRequisition($record)),
                Checkbox::make('invoice_after_close')
                    ->label('Faturar ao encerrar')
                    ->helperText('Se marcado, a requisição será faturada logo após o encerramento e a fatura será aberta em seguida.')
                    ->default(false),
            ])
            ->visible(fn (Requisition $record): bool => $record->status === Status::OPEN)
            ->action(function (Requisition $record, array $data): void {
                $shouldInvoiceAfterClose = (bool) ($data['invoice_after_close'] ?? false);

                Log::debug('CloseRequisitionAction (Filament): Encerrando requisição', [
                    'metodo'              => __METHOD__ . '@' . __LINE__,
                    'requisition_id'      => $record->id,
                    'user_id'             => Auth::id(),
                    'invoice_after_close' => $shouldInvoiceAfterClose,
                ]);

                $service = app(RequisitionService::class);
                $service->close($record, Auth::id(), (bool) ($data['send_email'] ?? false));

                if ($service->hasError()) {
                    Log::error('CloseRequisitionAction (Filament): Erro ao encerrar requisição', [
                        'metodo'         => __METHOD__ . '@' . __LINE__,
                        'requisition_id' => $record->id,
                        'error_code'     => $service->getErrorCode(),
                        'message'        => $service->getMessage(),
                    ]);

                    notify::error(
                        message: $service->getMessageUser(),
                        errorCode: $service->getErrorCode()
                    );

                    return;
                }

                if ($shouldInvoiceAfterClose) {
                    $invoice = $service->invoice($record->fresh(), Auth::id());

                    if ($service->hasError() || ! $invoice) {
                        Log::error('CloseRequisitionAction (Filament): Erro ao faturar requisição após encerramento', [
                            'metodo'         => __METHOD__ . '@' . __LINE__,
                            'requisition_id' => $record->id,
                            'error_code'     => $service->getErrorCode(),
                            'message'        => $service->getMessage(),
                        ]);

                        notify::error(
                            message: $service->getMessageUser(),
                            errorCode: $service->getErrorCode()
                        );

                        return;
                    }

                    Log::info('CloseRequisitionAction (Filament): Requisição encerrada e faturada com sucesso', [
                        'metodo'         => __METHOD__ . '@' . __LINE__,
                        'requisition_id' => $record->id,
                        'invoice_id'     => $invoice->id,
                    ]);

                    notify::success('Requisição encerrada e faturada com sucesso.');

                    return;
                }

                Log::info('CloseRequisitionAction (Filament): Requisição encerrada com sucesso', [
                    'metodo'         => __METHOD__ . '@' . __LINE__,
                    'requisition_id' => $record->id,
                ]);

                notify::success('Requisição encerrada com sucesso.');
            })
            ->successNotification(null)
            ->successRedirectUrl(function (Requisition $record): string {
                $record->refresh();

                if ($record->invoice_id) {
                    return InvoiceResource::getUrl('edit', ['record' => $record->invoice_id]);
                }

                return RequisitionResource::getUrl('edit', ['record' => $record->id]);
            });
    }
}
