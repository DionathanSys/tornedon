<?php

namespace App\Filament\Clusters\Sales\Resources\ProductionRequests\Pages\Actions;

use App\Enum\ProductionRequest\Status;
use App\Models\ProductionRequest;
use App\Notification\NotifyService as notify;
use App\Services\ProductionRequest\ProductionRequestService;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

final class CancelProductionRequestAction
{
    public static function make(): Action
    {
        return Action::make('cancel')
            ->label('Cancelar')
            ->icon(Heroicon::NoSymbol)
            ->color('danger')
            ->visible(fn (ProductionRequest $record): bool => $record->status === Status::OPEN)
            ->requiresConfirmation()
            ->action(function (ProductionRequest $record): void {
                $service = app(ProductionRequestService::class);

                if (! $service->cancel($record, Auth::id())) {
                    notify::error(message: $service->getMessageUser(), errorCode: $service->getErrorCode());

                    return;
                }

                $record->refresh();
                notify::success('Pedido cancelado com sucesso.');
            });
    }
}
