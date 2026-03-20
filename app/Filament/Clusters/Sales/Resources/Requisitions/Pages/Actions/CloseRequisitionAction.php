<?php

namespace App\Filament\Clusters\Sales\Resources\Requisitions\Pages\Actions;

use App\Enum\Requisition\Status;
use App\Models\Requisition;
use App\Notification\NotifyService as notify;
use App\Services\Requisition\RequisitionService;
use Filament\Actions\Action;
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
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Encerrar Requisição')
            ->modalDescription('Tem certeza que deseja encerrar esta requisição? Esta ação mudará o status para "Encerrada".')
            ->modalSubmitActionLabel('Sim, encerrar')
            ->visible(fn (Requisition $record): bool => $record->status === Status::OPEN)
            ->action(function (Requisition $record): void {
                Log::debug('CloseRequisitionAction (Filament): Encerrando requisição', [
                    'metodo'         => __METHOD__ . '@' . __LINE__,
                    'requisition_id' => $record->id,
                    'user_id'        => Auth::id(),
                ]);

                $service = app(RequisitionService::class);
                $service->close($record, Auth::id());

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

                Log::info('CloseRequisitionAction (Filament): Requisição encerrada com sucesso', [
                    'metodo'         => __METHOD__ . '@' . __LINE__,
                    'requisition_id' => $record->id,
                ]);

                notify::success('Requisição encerrada com sucesso.');
            })
            ->after(fn (Requisition $record) => $record->refreshFormData(['record']));
    }
}
