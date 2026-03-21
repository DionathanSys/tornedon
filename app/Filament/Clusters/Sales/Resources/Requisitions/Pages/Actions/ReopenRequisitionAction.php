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

final class ReopenRequisitionAction
{
    public static function make(): Action
    {
        return Action::make('reopenRequisition')
            ->label('Reabrir')
            ->icon(Heroicon::ArrowPath)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Reabrir Requisição')
            ->modalDescription('Tem certeza que deseja reabrir esta requisição? O status voltará para "Aberta".')
            ->modalSubmitActionLabel('Sim, reabrir')
            ->visible(fn (Requisition $record): bool => in_array($record->status, [Status::CLOSED, Status::CANCELLED]))
            ->action(function (Requisition $record): void {
                Log::debug('ReopenRequisitionAction (Filament): Reabrindo requisição', [
                    'metodo'         => __METHOD__ . '@' . __LINE__,
                    'requisition_id' => $record->id,
                    'user_id'        => Auth::id(),
                ]);

                $service = app(RequisitionService::class);
                $service->reopen($record, Auth::id());

                if ($service->hasError()) {
                    Log::error('ReopenRequisitionAction (Filament): Erro ao reabrir requisição', [
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

                Log::info('ReopenRequisitionAction (Filament): Requisição reaberta com sucesso', [
                    'metodo'         => __METHOD__ . '@' . __LINE__,
                    'requisition_id' => $record->id,
                ]);

                notify::success('Requisição reaberta com sucesso.');
            })
            ->successNotification(null)
            ->successRedirectUrl(fn (Requisition $record): string => RequisitionResource::getUrl('edit', ['record' => $record->id]));
    }
}
