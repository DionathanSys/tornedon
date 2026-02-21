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

final class ReopenServiceOrderAction
{
    public static function make(): Action
    {
        return Action::make('reopenServiceOrder')
            ->label('Reabrir')
            ->icon(Heroicon::ArrowPath)
            ->color('info')
            ->requiresConfirmation()
            ->modalHeading('Reabrir Ordem de Serviço')
            ->modalDescription('Tem certeza que deseja reabrir esta ordem de serviço? O status voltará para "Aberta".')
            ->modalSubmitActionLabel('Sim, reabrir')
            ->visible(fn (ServiceOrder $record): bool => in_array($record->status, [State::CLOSED, State::CANCELLED]))
            ->action(function (ServiceOrder $record): void {
                Log::debug('ReopenServiceOrderAction (Filament): Reabrindo OS', [
                    'metodo'           => __METHOD__ . '@' . __LINE__,
                    'service_order_id' => $record->id,
                    'user_id'          => Auth::id(),
                ]);

                $service = app(ServiceOrderService::class);
                $result = $service->reopen($record, Auth::id());

                if ($service->hasError()) {
                    Log::error('ReopenServiceOrderAction (Filament): Erro ao reabrir OS', [
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

                Log::info('ReopenServiceOrderAction (Filament): OS reaberta com sucesso', [
                    'metodo'           => __METHOD__ . '@' . __LINE__,
                    'service_order_id' => $record->id,
                ]);

                notify::success('Ordem de serviço reaberta com sucesso.');
            });
    }
}
