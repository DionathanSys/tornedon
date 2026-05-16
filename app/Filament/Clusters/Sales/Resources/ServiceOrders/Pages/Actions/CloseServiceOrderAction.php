<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions;

use App\Enum\ServiceOrder\State;
use App\Filament\Clusters\Financial\Resources\Invoices\InvoiceResource;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\ServiceOrderResource;
use App\Models\ServiceOrder;
use App\Notification\NotifyService as notify;
use App\Services\Email\DocumentNotificationService;
use App\Services\ServiceOrder\CloseServiceOrderWorkflow;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
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
            ->modalDescription('Tem certeza que deseja encerrar esta ordem de serviço? Se houver requisição vinculada, ela também será encerrada.')
            ->modalSubmitActionLabel('Sim')
            ->schema([
                Checkbox::make('send_email')
                    ->label('Enviar e-mail ao encerrar?')
                    ->default(fn(ServiceOrder $record): bool => app(DocumentNotificationService::class)->shouldSendForServiceOrder($record)),
                Checkbox::make('invoice_after_close')
                    ->label('Faturar ao encerrar')
                    ->helperText('Se marcado, a ordem de serviço será faturada ao final do encerramento. Se houver requisição vinculada, ela será faturada na mesma fatura.')
                    ->default(false),
            ])
            ->visible(fn(ServiceOrder $record): bool => $record->status === State::OPEN)
            ->action(function (ServiceOrder $record, array $data): void {
                Log::debug('CloseServiceOrderAction (Filament): Encerrando OS', [
                    'metodo' => __METHOD__ . '@' . __LINE__,
                    'service_order_id' => $record->id,
                    'user_id' => Auth::id(),
                ]);

                $workflow = app(CloseServiceOrderWorkflow::class);
                $result = $workflow->execute(
                    serviceOrder: $record,
                    userId: Auth::id(),
                    sendEmail: (bool) ($data['send_email'] ?? false),
                    shouldInvoiceAfterClose: (bool) ($data['invoice_after_close'] ?? false),
                );

                if (! $result) {
                    Log::error('CloseServiceOrderAction (Filament): Erro no workflow de fechamento da OS', [
                        'metodo' => __METHOD__ . '@' . __LINE__,
                        'service_order_id' => $record->id,
                        'error_code' => $workflow->getErrorCode(),
                        'message' => $workflow->getMessage(),
                    ]);

                    notify::error(
                        message: $workflow->getMessageUser(),
                        errorCode: $workflow->getErrorCode()
                    );

                    return;
                }

                if ($workflow->invoice()) {
                    Log::info('CloseServiceOrderAction (Filament): OS encerrada e faturada com sucesso', [
                        'metodo' => __METHOD__ . '@' . __LINE__,
                        'service_order_id' => $record->id,
                        'invoice_id' => $workflow->invoice()?->id,
                    ]);

                    notify::success(
                        $workflow->closedLinkedRequisition()
                            ? 'Ordem de serviço e requisição vinculada encerradas e faturadas com sucesso.'
                            : 'Ordem de serviço encerrada e faturada com sucesso.'
                    );

                    return;
                }

                Log::info('CloseServiceOrderAction (Filament): OS encerrada com sucesso', [
                    'metodo' => __METHOD__ . '@' . __LINE__,
                    'service_order_id' => $record->id,
                ]);

                notify::success(
                    $workflow->closedLinkedRequisition()
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
