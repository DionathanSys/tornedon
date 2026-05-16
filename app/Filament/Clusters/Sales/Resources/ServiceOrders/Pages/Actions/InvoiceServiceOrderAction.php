<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions;

use App\Enum\ServiceOrder\State;
use App\Filament\Clusters\Financial\Resources\Invoices\InvoiceResource;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\ServiceOrderResource;
use App\Models\ServiceOrder;
use App\Notification\NotifyService as notify;
use App\Services\ServiceOrder\InvoiceServiceOrderWorkflow;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class InvoiceServiceOrderAction
{
    public static function make(): Action
    {
        return Action::make('invoiceServiceOrder')
            ->label('Faturar')
            ->icon(Heroicon::DocumentText)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Faturar Ordem de Serviço')
            ->modalDescription('Tem certeza que deseja faturar esta ordem de serviço? Se houver requisição vinculada, ela será faturada na mesma fatura.')
            ->modalSubmitActionLabel('Sim, faturar')
            ->visible(fn (ServiceOrder $record): bool => $record->status === State::CLOSED)
            ->action(function (ServiceOrder $record): void {
                Log::debug('InvoiceServiceOrderAction (Filament): Faturando OS', [
                    'metodo' => __METHOD__ . '@' . __LINE__,
                    'service_order_id' => $record->id,
                    'user_id' => Auth::id(),
                ]);

                $workflow = app(InvoiceServiceOrderWorkflow::class);
                $result = $workflow->execute($record, Auth::id());

                if (! $result) {
                    Log::error('InvoiceServiceOrderAction (Filament): Erro ao faturar OS', [
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

                Log::info('InvoiceServiceOrderAction (Filament): OS faturada com sucesso', [
                    'metodo' => __METHOD__ . '@' . __LINE__,
                    'service_order_id' => $record->id,
                    'invoice_id' => $workflow->invoice()?->id,
                ]);

                notify::success('Ordem de serviço faturada com sucesso.');
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
