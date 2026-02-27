<?php

namespace App\Filament\Clusters\Inventory\Resources\StockMovements\Actions;

use App\Models\StockMovement;
use App\Notification\NotifyService as notify;
use App\Services\StockMovement\StockMovementService;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class CreateStockMovementFromModalAction
{
    public static function make(): Action
    {
        return Action::make('createFromModal')
            ->label('Registrar Movimentação')
            ->icon(Heroicon::Plus)
            ->schema([
                // Formulário será definido no componente que usa esta action
            ])
            ->action(function (Action $action, array $data): void {
                Log::debug('CreateStockMovementFromModalAction: Criando movimentação via action', [
                    'metodo'  => __METHOD__ . '@' . __LINE__,
                    'data'    => $data,
                    'user_id' => Auth::id(),
                ]);

                $service = app(StockMovementService::class);
                $movement = $service->create($data, Auth::id());

                if ($service->hasError()) {
                    Log::error('CreateStockMovementFromModalAction: ' . $service->getMessage(), [
                        'metodo'     => __METHOD__ . '@' . __LINE__,
                        'error_code' => $service->getErrorCode(),
                        'message'    => $service->getMessage(),
                    ]);

                    notify::error(
                        message: $service->getMessageUser(),
                        errorCode: $service->getErrorCode()
                    );

                    $action->halt();
                }

                Log::info('CreateStockMovementFromModalAction: Movimentação criada com sucesso', [
                    'metodo'             => __METHOD__ . '@' . __LINE__,
                    'stock_movement_id'  => $movement->id,
                ]);

                notify::success('Movimentação de estoque registrada com sucesso.');
            });
    }
}
