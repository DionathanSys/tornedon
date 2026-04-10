<?php

namespace App\Filament\Clusters\Financial\Resources\CashMovements\Actions;

use App\Models\CashMovement;
use App\Notification\NotifyService as notify;
use App\Services\Financial\CashMovementService;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

final class ReverseTransferAction
{
    public static function make(): Action
    {
        return Action::make('reverse_transfer')
            ->label('Estornar transferencia')
            ->icon(Heroicon::ArrowUturnLeft)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Estornar transferencia')
            ->modalDescription('Os dois lados da transferencia serao estornados juntos.')
            ->visible(fn (CashMovement $record): bool => $record->isTransfer() && $record->reversed_at === null)
            ->action(function (Action $action, CashMovement $record): void {
                $service = app(CashMovementService::class);
                $reversal = $service->reverseTransfer($record, Auth::id());

                if ($service->hasError() || $reversal === null) {
                    notify::error(message: $service->getMessageUser());
                    $action->halt();

                    return;
                }

                notify::success(message: $service->getMessageUser());
            });
    }
}
