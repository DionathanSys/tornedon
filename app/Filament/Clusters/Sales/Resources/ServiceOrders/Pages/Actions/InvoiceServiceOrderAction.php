<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions;

use App\Enum\ServiceOrder\State;
use App\Models\ServiceOrder;
use App\Notification\NotifyService as notify;
use App\Services\ServiceOrder\ServiceOrderService;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class InvoiceServiceOrderAction
{
    public static function make(): Action
    {
        return Action::make('invoiceServiceOrder')
            ->label('Faturar')
            ->icon(Heroicon::DocumentText)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Faturar Ordem de Serviço')
            ->modalDescription('Tem certeza que deseja faturar esta ordem de serviço? Esta ação mudará o status para "Faturada" e não poderá ser desfeita.')
            ->modalSubmitActionLabel('Sim, faturar')
            ->schema([
                Checkbox::make('request_fiscal_document')
                    ->label('Solicitar documento fiscal agora')
                    ->helperText('Se desmarcado, a fatura será criada em aberto para posterior emissão do documento fiscal.')
                    ->default(false),
            ])
            ->visible(fn (ServiceOrder $record): bool => $record->status === State::CLOSED)
            ->action(function (ServiceOrder $record, array $data): void {
                Log::debug('InvoiceServiceOrderAction (Filament): Faturando OS', [
                    'metodo'                   => __METHOD__ . '@' . __LINE__,
                    'service_order_id'         => $record->id,
                    'user_id'                  => Auth::id(),
                    'request_fiscal_document'  => $data['request_fiscal_document'] ?? false,
                ]);

                $service = app(ServiceOrderService::class);
                $result = $service->invoice($record, Auth::id());

                if ($service->hasError()) {
                    Log::error('InvoiceServiceOrderAction (Filament): Erro ao faturar OS', [
                        'metodo'           => __METHOD__ . '@' . __LINE__,
                        'service_order_id' => $record->id,
                        'error_code'       => $service->getErrorCode(),
                        'message'          => $service->getMessage(),
                    ]);

                    notify::error(
                        message: $service->getMessageUser(),
                        errorCode: $service->getErrorCode()
                    );

                    return;
                }

                Log::info('InvoiceServiceOrderAction (Filament): OS faturada com sucesso', [
                    'metodo'           => __METHOD__ . '@' . __LINE__,
                    'service_order_id' => $record->id,
                ]);

                notify::success('Ordem de serviço faturada com sucesso.');
            });
    }
}
