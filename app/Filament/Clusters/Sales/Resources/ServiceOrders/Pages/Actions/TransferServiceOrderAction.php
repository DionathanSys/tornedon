<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions;

use App\Enum\ServiceOrder\State;
use App\Filament\Clusters\Sales\Resources\Components\SelectPartner;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\ServiceOrderResource;
use App\Models\Equipment;
use App\Models\ServiceOrder;
use App\Notification\NotifyService as notify;
use App\Services\Equipment\EquipmentService;
use App\Services\ServiceOrder\ServiceOrderService;
use Filament\Actions\Action;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class TransferServiceOrderAction
{
    public static function make(): Action
    {
        return Action::make('transferServiceOrder')
            ->label('Transferir')
            ->icon(Heroicon::ArrowsRightLeft)
            ->color('warning')
            ->modalWidth(Width::ExtraLarge)
            ->modalHeading('Transferir ordem de serviço')
            ->modalDescription('Transfere a OS para outro cliente. A requisição vinculada acompanha a mudança e o equipamento será reaproveitado ou criado automaticamente quando necessário.')
            ->modalSubmitActionLabel('Transferir')
            ->schema([
                SelectPartner::make('customer_id', 'customer')
                    ->label('Cliente de destino')
                    ->live()
                    ->helperText('Selecione o cliente que receberá a ordem de serviço. O equipamento será localizado ou criado automaticamente ao confirmar.'),
                Callout::make('existing-transfer-equipment')
                    ->info()
                    ->heading('Equipamento encontrado')
                    ->visible(fn (ServiceOrder $record, Get $get): bool => self::resolveMatchingEquipment($record, $get) !== null)
                    ->description(fn (ServiceOrder $record, Get $get): string => sprintf(
                        'Equipamento encontrado para o cliente de destino: %s. Ele será vinculado automaticamente à ordem de serviço e à requisição vinculada.',
                        self::formatEquipmentLabel(self::resolveMatchingEquipment($record, $get))
                    )),
                Callout::make('missing-transfer-equipment')
                    ->warning()
                    ->heading('Equipamento será criado')
                    ->visible(fn (ServiceOrder $record, Get $get): bool => filled($get('customer_id')) && $record->equipment !== null && self::resolveMatchingEquipment($record, $get) === null)
                    ->description('Nenhum equipamento equivalente foi encontrado para o cliente de destino. Um novo equipamento será criado automaticamente ao confirmar a transferência.'),
                Callout::make('no-origin-equipment')
                    ->warning()
                    ->heading('Ordem sem equipamento')
                    ->visible(fn (ServiceOrder $record, Get $get): bool => filled($get('customer_id')) && $record->equipment === null)
                    ->description('Esta ordem de serviço não possui equipamento vinculado. A transferência será concluída sem equipamento para a ordem e para a requisição vinculada.'),
            ])
            ->fillForm(fn (ServiceOrder $record): array => ['customer_id' => null])
            ->visible(fn (ServiceOrder $record): bool => in_array($record->status, [State::OPEN, State::CLOSED], true))
            ->action(function (ServiceOrder $record, array $data): void {
                Log::debug('TransferServiceOrderAction (Filament): transferindo OS', [
                    'metodo' => __METHOD__.'@'.__LINE__,
                    'service_order_id' => $record->id,
                    'target_customer_id' => $data['customer_id'] ?? null,
                    'user_id' => Auth::id(),
                ]);

                $service = app(ServiceOrderService::class);
                $transferred = $service->transfer(
                    $record,
                    (int) $data['customer_id'],
                    null,
                    Auth::id(),
                );

                if ($service->hasError() || $transferred === null) {
                    Log::error('TransferServiceOrderAction (Filament): erro ao transferir OS', [
                        'metodo' => __METHOD__.'@'.__LINE__,
                        'service_order_id' => $record->id,
                        'error_code' => $service->getErrorCode(),
                        'message' => $service->getMessage(),
                        'errors' => $service->getErrors(),
                    ]);

                    notify::error(
                        message: $service->getMessageUser(),
                        errorCode: $service->getErrorCode(),
                    );

                    return;
                }

                notify::success(message: 'Ordem de serviço transferida com sucesso.');
            })
            ->successRedirectUrl(fn (ServiceOrder $record, $livewire): string => self::resolveResource($livewire)::getUrl('edit', ['record' => $record->id]));
    }

    private static function resolveResource(mixed $livewire): string
    {
        if (is_object($livewire) && method_exists($livewire, 'getResource')) {
            return $livewire->getResource();
        }

        return ServiceOrderResource::class;
    }

    private static function resolveMatchingEquipment(ServiceOrder $record, Get $get): ?Equipment
    {
        $customerId = $get('customer_id');

        if (blank($customerId)) {
            return null;
        }

        return app(ServiceOrderService::class)->findMatchingTransferEquipment($record, (int) $customerId);
    }

    private static function formatEquipmentLabel(?Equipment $equipment): string
    {
        if ($equipment === null) {
            return '-';
        }

        return (new EquipmentService)->getLabelForSelect($equipment->id, ['owner' => false])
            ?? ('Equipamento #'.$equipment->id);
    }
}
