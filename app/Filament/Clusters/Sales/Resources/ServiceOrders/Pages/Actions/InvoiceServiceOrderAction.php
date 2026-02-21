<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions;

use App\Enum\ServiceOrder\State;
use App\Models\ServiceOrder;
use App\Notification\NotifyService as notify;
use App\Services\ServiceOrder\ServiceOrderService;
use Filament\Actions\Action;
use Filament\Support\Enums\ActionSize;
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
            ->modalDescription('Tem certeza que deseja faturar esta ordem de serviço? Esta ação mudará o status para "Faturada" e não poderá ser desfeita.')
            ->modalSubmitActionLabel('Sim, faturar')
            ->visible(fn (ServiceOrder $record): bool => $record->status === State::CLOSED)
            ->action(function (ServiceOrder $record): void {
                Log::debug('InvoiceServiceOrderAction (Filament): Faturando OS', [
                    'metodo'           => __METHOD__ . '@' . __LINE__,
                    'service_order_id' => $record->id,
                    'user_id'          => Auth::id(),
                ]);

                $service = app(ServiceOrderService::class);
                $result = $service->invoice($record, Auth::id());

                if ($service->hasError()) {
                    Log::error('InvoiceServiceOrderAction (Filament): Erro ao faturar OS', [
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

                Log::info('InvoiceServiceOrderAction (Filament): OS faturada com sucesso', [
                    'metodo'           => __METHOD__ . '@' . __LINE__,
                    'service_order_id' => $record->id,
                ]);

                notify::success('Ordem de serviço faturada com sucesso.');
            });
    }
}
