<?php

namespace App\Filament\Clusters\Inventory\Resources\StockMovements\Actions;

use App\Models\StockMovement;
use App\Notification\NotifyService as notify;
use App\Services\StockMovement\StockMovementService;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class RestoreStockMovementAction
{
    public static function make(): Action
    {
        return Action::make('restore')
            ->label('Restaurar')
            ->icon(Heroicon::ArrowUturnLeft)
            ->color('info')
            ->requiresConfirmation()
            ->modalHeading('Restaurar Movimentação')
            ->modalDescription('Tem certeza que deseja restaurar esta movimentação de estoque? Ela voltará a ser visível normalmente.')
            ->modalSubmitActionLabel('Sim, restaurar')
            ->visible(fn(StockMovement $record): bool => $record->deleted_at !== null)
            ->action(function (StockMovement $record): void {
                Log::debug('RestoreStockMovementAction: Restaurando movimentação', [
                    'metodo'             => __METHOD__ . '@' . __LINE__,
                    'stock_movement_id'  => $record->id,
                    'user_id'            => Auth::id(),
                ]);

                $service = app(StockMovementService::class);
                $restored = $service->restore($record->id);

                if ($service->hasError()) {
                    Log::error('RestoreStockMovementAction: Erro ao restaurar movimentação', [
                        'metodo'             => __METHOD__ . '@' . __LINE__,
                        'stock_movement_id'  => $record->id,
                        'error_code'         => $service->getErrorCode(),
                        'message'            => $service->getMessage(),
                    ]);

                    notify::error(
                        message: $service->getMessageUser(),
                        errorCode: $service->getErrorCode()
                    );

                    return;
                }

                Log::info('RestoreStockMovementAction: Movimentação restaurada com sucesso', [
                    'metodo'             => __METHOD__ . '@' . __LINE__,
                    'stock_movement_id'  => $restored->id,
                ]);

                notify::success('Movimentação de estoque restaurada com sucesso.');

                redirect(url()->current());
            });
    }
}
