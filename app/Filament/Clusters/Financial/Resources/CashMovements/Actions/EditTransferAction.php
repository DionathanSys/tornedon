<?php

namespace App\Filament\Clusters\Financial\Resources\CashMovements\Actions;

use App\Enum\Financial\CashMovementDirection;
use App\Filament\Clusters\Financial\Resources\CashMovements\Schemas\TransferCashMovementActionForm;
use App\Models\CashMovement;
use App\Notification\NotifyService as notify;
use App\Services\Financial\CashMovementService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

final class EditTransferAction
{
    public static function make(): Action
    {
        return Action::make('edit_transfer')
            ->label('Editar transferencia')
            ->icon(Heroicon::PencilSquare)
            ->color('warning')
            ->modalWidth('3xl')
            ->visible(fn (CashMovement $record): bool => $record->isTransfer() && $record->reversed_at === null)
            ->schema(TransferCashMovementActionForm::components())
            ->fillForm(fn (CashMovement $record): array => self::fillData($record))
            ->action(function (Action $action, CashMovement $record, array $data): void {
                $service = app(CashMovementService::class);
                $updated = $service->updateTransfer($record, [
                    ...$data,
                    'company_id' => Filament::getTenant()->id,
                ], Auth::id());

                if ($service->hasError() || $updated === null) {
                    notify::error(message: $service->getMessageUser());
                    $action->halt();

                    return;
                }

                notify::success(message: $service->getMessageUser());
            });
    }

    private static function fillData(CashMovement $record): array
    {
        $counterpart = $record->transferCounterpart();
        $source = $record->direction === CashMovementDirection::OUTFLOW ? $record : $counterpart;
        $destination = $record->direction === CashMovementDirection::INFLOW ? $record : $counterpart;

        return [
            'source_financial_account_id' => $source?->financial_account_id,
            'destination_financial_account_id' => $destination?->financial_account_id,
            'financial_category_id' => $source?->financial_category_id ?? $record->financial_category_id,
            'transaction_date' => $source?->transaction_date?->format('Y-m-d') ?? $record->transaction_date?->format('Y-m-d'),
            'amount' => $source?->amount ?? $record->amount,
            'description' => $source?->description ?? $record->description,
            'notes' => $source?->notes ?? $record->notes,
        ];
    }
}
