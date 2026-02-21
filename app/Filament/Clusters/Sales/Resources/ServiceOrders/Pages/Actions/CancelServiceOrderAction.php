<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions;

use App\Enum\ServiceOrder\State;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\ServiceOrderResource;
use App\Models\ServiceOrder;
use App\Notification\NotifyService as notify;
use App\Services\ServiceOrder\ServiceOrderService;
use Filament\Actions\Action;
use Filament\Support\Enums\ActionSize;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class CancelServiceOrderAction
{
    public static function make(): Action
    {
        return Action::make('cancelServiceOrder')
            ->label('Cancelar')
            ->icon(Heroicon::XCircle)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Cancelar Ordem de Serviço')
            ->modalDescription('Tem certeza que deseja cancelar esta ordem de serviço? Esta ação mudará o status para "Cancelada".')
            ->modalSubmitActionLabel('Sim, cancelar')
            ->visible(fn (ServiceOrder $record): bool => $record->status === State::OPEN)
            ->action(function (ServiceOrder $record): void {
                Log::debug('CancelServiceOrderAction (Filament): Cancelando OS', [
                    'metodo'           => __METHOD__ . '@' . __LINE__,
                    'service_order_id' => $record->id,
                    'user_id'          => Auth::id(),
                ]);

                $service = app(ServiceOrderService::class);
                $result = $service->cancel($record, Auth::id());

                if ($service->hasError()) {
                    Log::error('CancelServiceOrderAction (Filament): Erro ao cancelar OS', [
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

                Log::info('CancelServiceOrderAction (Filament): OS cancelada com sucesso', [
                    'metodo'           => __METHOD__ . '@' . __LINE__,
                    'service_order_id' => $record->id,
                ]);

                notify::success('Ordem de serviço cancelada com sucesso.');
            })
            ->successRedirectUrl(ServiceOrderResource::getUrl());
    }
}
