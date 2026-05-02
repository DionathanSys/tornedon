<?php

namespace App\Filament\Clusters\Financial\Resources\PurchaseClosings\Pages\Actions;

use App\Enum\PurchaseClosing\Status;
use App\Models\PurchaseClosing;
use App\Notification\NotifyService as notify;
use App\Services\PurchaseClosing\PurchaseClosingService;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

class ReopenPurchaseClosingAction
{
    public static function make(): Action
    {
        return Action::make('reopenPurchaseClosing')
            ->label('Reabrir Fechamento')
            ->icon(Heroicon::ArrowPath)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Reabrir fechamento')
            ->modalDescription('O fechamento só pode ser reaberto se não houver conta a pagar vinculada.')
            ->visible(fn (PurchaseClosing $record): bool => ! $record->account_payable_id
                && $record->status === Status::CLOSED)
            ->action(function (PurchaseClosing $record): void {
                $service = app(PurchaseClosingService::class);
                $reopened = $service->reopen($record, (int) Auth::id());

                if ($service->hasError() || $reopened === null) {
                    notify::error(message: $service->getMessageUser(), errorCode: $service->getErrorCode());
                    return;
                }

                notify::success('Fechamento reaberto com sucesso.');
            });
    }
}
