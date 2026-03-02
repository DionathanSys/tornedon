<?php

namespace App\Filament\Clusters\Inventory\Resources\StockMovements\Actions;

use App\Filament\Clusters\Inventory\Resources\StockMovements\Schemas\StockMovementForm;
use App\Notification\NotifyService as notify;
use App\Services\StockMovement\StockMovementService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
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
            ->modalWidth('2xl')
            ->modalHeading('Registrar Movimentação de Estoque')
            ->schema(StockMovementForm::schema())
            ->action(function (Action $action, array $data): void {
                // Garante company_id mesmo que o hidden não seja enviado
                $data['company_id'] ??= Filament::getTenant()->id;

                Log::debug('CreateStockMovementFromModalAction: Criando movimentação via action', [
                    'metodo'  => __METHOD__ . '@' . __LINE__,
                    'data'    => $data,
                    'user_id' => Auth::id(),
                ]);

                $data['source_id'] =  1;

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
                    return;
                }

                Log::info('CreateStockMovementFromModalAction: Movimentação criada com sucesso', [
                    'metodo'             => __METHOD__ . '@' . __LINE__,
                    'stock_movement_id'  => $movement->id,
                ]);

                notify::success('Movimentação de estoque registrada com sucesso.');
            });
    }
}

