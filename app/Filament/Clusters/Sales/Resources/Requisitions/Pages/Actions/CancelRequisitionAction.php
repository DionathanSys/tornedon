<?php

namespace App\Filament\Clusters\Sales\Resources\Requisitions\Pages\Actions;

use App\Enum\Requisition\Status;
use App\Filament\Clusters\Sales\Resources\Requisitions\RequisitionResource;
use App\Models\Requisition;
use App\Notification\NotifyService as notify;
use App\Services\Requisition\RequisitionService;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class CancelRequisitionAction
{
    public static function make(): Action
    {
        return Action::make('cancelRequisition')
            ->label('Cancelar')
            ->icon(Heroicon::XCircle)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Cancelar Requisição')
            ->modalDescription('Tem certeza que deseja cancelar esta requisição? Esta ação mudará o status para "Cancelada".')
            ->modalSubmitActionLabel('Sim, cancelar')
            ->visible(fn (Requisition $record): bool => $record->status === Status::OPEN)
            ->action(function (Requisition $record): void {
                Log::debug('CancelRequisitionAction (Filament): Cancelando requisição', [
                    'metodo'         => __METHOD__ . '@' . __LINE__,
                    'requisition_id' => $record->id,
                    'user_id'        => Auth::id(),
                ]);

                $service = app(RequisitionService::class);
                $service->cancel($record, Auth::id());

                if ($service->hasError()) {
                    Log::error('CancelRequisitionAction (Filament): Erro ao cancelar requisição', [
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

                Log::info('CancelRequisitionAction (Filament): Requisição cancelada com sucesso', [
                    'metodo'         => __METHOD__ . '@' . __LINE__,
                    'requisition_id' => $record->id,
                ]);

                notify::success('Requisição cancelada com sucesso.');
            })
            ->successRedirectUrl(RequisitionResource::getUrl());
    }
}
