<?php

namespace App\Filament\Clusters\Financial\Resources\CashMovements\Actions;

use App\Filament\Clusters\Financial\Resources\CashMovements\Schemas\TransferCashMovementActionForm;
use App\Notification\NotifyService as notify;
use App\Services\Financial\CashMovementService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

final class CreateTransferAction
{
    public static function make(): Action
    {
        return Action::make('create_transfer')
            ->label('Transferencia entre contas')
            ->icon(Heroicon::ArrowsRightLeft)
            ->color('info')
            ->modalWidth('3xl')
            ->schema(TransferCashMovementActionForm::components())
            ->action(function (Action $action, array $data): void {
                $service = app(CashMovementService::class);
                $transfer = $service->createTransfer([
                    ...$data,
                    'company_id' => Filament::getTenant()->id,
                ], Auth::id());

                if ($service->hasError() || $transfer === null) {
                    notify::error(message: $service->getMessageUser());
                    $action->halt();

                    return;
                }

                notify::success(message: $service->getMessageUser());
            });
    }
}
