<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions;

use App\Enum\ServiceOrder\State;
use App\Filament\Clusters\Financial\Resources\Invoices\InvoiceResource;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\ServiceOrderResource;
use App\Models\ServiceOrder;
use App\Notification\NotifyService as notify;
use App\Services\Email\DocumentNotificationService;
use App\Services\ServiceOrder\ServiceOrderService;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Toggle;
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
                Toggle::make('send_email')
                    ->label('Enviar e-mail ao encerrar?')
                    ->default(fn(ServiceOrder $record): bool => app(DocumentNotificationService::class)->shouldSendForServiceOrder($record))
                    ->inline(false),
                Checkbox::make('invoice_after_close')
                    ->label('Faturar ao encerrar')
                    ->helperText('Se marcado, a ordem de serviço será faturada logo após o encerramento.')
                    ->default(false),
            ])
            ->visible(fn(ServiceOrder $record): bool => $record->status === State::OPEN)
            ->action(function (ServiceOrder $record, array $data): void {
                $shouldInvoiceAfterClose = (bool) ($data['invoice_after_close'] ?? false);

                Log::debug('CloseServiceOrderAction (Filament): Encerrando OS', [
                    'metodo'              => __METHOD__ . '@' . __LINE__,
                    'service_order_id'    => $record->id,
                    'user_id'             => Auth::id(),
                    'invoice_after_close' => $shouldInvoiceAfterClose,
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

                    Log::info('CloseServiceOrderAction (Filament): OS encerrada e faturada com sucesso', [
                        'metodo'           => __METHOD__ . '@' . __LINE__,
                        'service_order_id' => $record->id,
                        'invoice_id'       => $invoice->id,
                    ]);

                    notify::success('Ordem de serviço encerrada e faturada com sucesso.');

                    return;
                }

                Log::info('CloseServiceOrderAction (Filament): OS encerrada com sucesso', [
                    'metodo'           => __METHOD__ . '@' . __LINE__,
                    'service_order_id' => $record->id,
                ]);

                notify::success('Ordem de serviço encerrada com sucesso.');
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
