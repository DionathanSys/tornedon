<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions;

use App\Enum\ServiceOrder\State;
use App\Models\ServiceOrder;
use App\Notification\NotifyService as notify;
use App\Services\Email\DocumentNotificationService;
use App\Services\ServiceOrder\ServiceOrderService;
use Filament\Actions\Action;
use Filament\Forms\Components\Toggle;
use Filament\Support\Enums\ActionSize;
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
            ->requiresConfirmation()
            ->modalHeading('Encerrar Ordem de Serviço')
            ->modalDescription('Tem certeza que deseja encerrar esta ordem de serviço? Esta ação mudará o status para "Encerrada".')
            ->modalSubmitActionLabel('Sim, encerrar')
            ->form([
                Toggle::make('send_email')
                    ->label('Enviar e-mail ao encerrar?')
                    ->default(fn (ServiceOrder $record): bool => app(DocumentNotificationService::class)->shouldSendForServiceOrder($record))
                    ->inline(false),
            ])
            ->visible(fn (ServiceOrder $record): bool => $record->status === State::OPEN)
            ->action(function (ServiceOrder $record, array $data): void {
                Log::debug('CloseServiceOrderAction (Filament): Encerrando OS', [
                    'metodo'           => __METHOD__ . '@' . __LINE__,
                    'service_order_id' => $record->id,
                    'user_id'          => Auth::id(),
                ]);

                $service = app(ServiceOrderService::class);
                $result = $service->close($record, Auth::id(), (bool) ($data['send_email'] ?? false));

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

                Log::info('CloseServiceOrderAction (Filament): OS encerrada com sucesso', [
                    'metodo'           => __METHOD__ . '@' . __LINE__,
                    'service_order_id' => $record->id,
                ]);

                notify::success('Ordem de serviço encerrada com sucesso.');
            });
    }
}
