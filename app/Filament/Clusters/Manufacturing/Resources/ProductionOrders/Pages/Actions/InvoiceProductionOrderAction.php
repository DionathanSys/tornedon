<?php

namespace App\Filament\Clusters\Manufacturing\Resources\ProductionOrders\Pages\Actions;

use App\Enum\ProductionOrder\Status;
use App\Models\ProductionOrder;
use App\Notification\NotifyService as notify;
use App\Services\ProductionOrder\ProductionOrderService;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class InvoiceProductionOrderAction
{
    public static function make(): Action
    {
        return Action::make('invoiceProductionOrder')
            ->label('Faturar')
            ->icon(Heroicon::DocumentText)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Faturar Ordem de Produção')
            ->modalDescription('Tem certeza que deseja faturar esta ordem de produção? Esta ação criará a fatura, o documento fiscal e enfileirará a emissão da NF-e.')
            ->modalSubmitActionLabel('Sim, faturar')
            ->visible(fn (ProductionOrder $record): bool => $record->status === Status::COMPLETED)
            ->action(function (ProductionOrder $record): void {
                Log::debug('InvoiceProductionOrderAction (Filament): Faturando OP', [
                    'metodo'              => __METHOD__ . '@' . __LINE__,
                    'production_order_id' => $record->id,
                    'user_id'             => Auth::id(),
                ]);

                $service = app(ProductionOrderService::class);
                $result = $service->invoice($record, Auth::id());

                if ($service->hasError()) {
                    Log::error('InvoiceProductionOrderAction (Filament): Erro ao faturar OP', [
                        'metodo'              => __METHOD__ . '@' . __LINE__,
                        'production_order_id' => $record->id,
                        'error_code'          => $service->getErrorCode(),
                        'message'             => $service->getMessage(),
                    ]);

                    notify::error(
                        message: $service->getMessageUser(),
                        errorCode: $service->getErrorCode()
                    );

                    return;
                }

                Log::info('InvoiceProductionOrderAction (Filament): OP faturada com sucesso', [
                    'metodo'              => __METHOD__ . '@' . __LINE__,
                    'production_order_id' => $record->id,
                ]);

                notify::success('Ordem de produção faturada com sucesso.');
            });
    }
}
