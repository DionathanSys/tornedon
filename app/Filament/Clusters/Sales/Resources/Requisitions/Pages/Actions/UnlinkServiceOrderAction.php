<?php

namespace App\Filament\Clusters\Sales\Resources\Requisitions\Pages\Actions;

use App\Models\Requisition;
use App\Notification\NotifyService as notify;
use App\Services\Requisition\RequisitionService;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class UnlinkServiceOrderAction
{
    public static function make(): Action
    {
        return Action::make('unlinkServiceOrder')
            ->label('Desvincular OS')
            ->icon(Heroicon::LinkSlash)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Desvincular ordem de serviço')
            ->modalDescription('Tem certeza que deseja remover o vínculo entre esta requisição e a ordem de serviço?')
            ->modalSubmitActionLabel('Sim, desvincular')
            ->visible(fn (Requisition $record): bool => filled($record->service_order_id))
            ->action(function (Requisition $record): void {
                Log::debug('UnlinkServiceOrderAction (Filament): desvinculando requisição da OS', [
                    'metodo' => __METHOD__ . '@' . __LINE__,
                    'requisition_id' => $record->id,
                    'service_order_id' => $record->service_order_id,
                    'user_id' => Auth::id(),
                ]);

                $service = app(RequisitionService::class);
                $result = $service->unlinkServiceOrder($record, Auth::id());

                if ($service->hasError() || $result === null) {
                    Log::error('UnlinkServiceOrderAction (Filament): erro ao desvincular requisição da OS', [
                        'metodo' => __METHOD__ . '@' . __LINE__,
                        'requisition_id' => $record->id,
                        'service_order_id' => $record->service_order_id,
                        'error_code' => $service->getErrorCode(),
                        'message' => $service->getMessage(),
                    ]);

                    notify::error(
                        message: $service->getMessageUser(),
                        errorCode: $service->getErrorCode()
                    );

                    return;
                }

                notify::success(message: 'Requisição desvinculada da ordem de serviço com sucesso.');
            });
    }
}
