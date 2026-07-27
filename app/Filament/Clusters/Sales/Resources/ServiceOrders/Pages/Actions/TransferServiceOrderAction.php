<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions;

use App\Enum\ServiceOrder\State;
use App\Filament\Clusters\Sales\Resources\Components\SelectPartner;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\ServiceOrderResource;
use App\Models\ServiceOrder;
use App\Notification\NotifyService as notify;
use App\Services\Equipment\EquipmentService;
use App\Services\ServiceOrder\ServiceOrderService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
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
                    ->afterStateUpdated(fn (Set $set) => $set('equipment_id', null))
                    ->helperText('Selecione o cliente que receberá a ordem de serviço e o equipamento.'),
                Select::make('equipment_id')
                    ->label('Equipamento no cliente de destino')
                    ->searchable()
                    ->native(false)
                    ->dehydrated(true)
                    ->getSearchResultsUsing(
                        fn (string $search, Get $get): array => (new EquipmentService)
                            ->searchForSelect($search, Filament::getTenant()->id, $get('customer_id'), 20, ['owner' => false])
                    )
                    ->getOptionLabelUsing(
                        fn ($value): ?string => $value ? (new EquipmentService)
                            ->getLabelForSelect((int) $value, ['owner' => false]) : null
                    )
                    ->disabled(fn (Get $get) => blank($get('customer_id')))
                    ->helperText('Opcional. Se não selecionar, o sistema tenta localizar um equipamento equivalente do cliente de destino e, se não encontrar, cria um novo automaticamente.'),
            ])
            ->fillForm(fn (ServiceOrder $record): array => [
                'customer_id' => null,
                'equipment_id' => null,
            ])
            ->visible(fn (ServiceOrder $record): bool => in_array($record->status, [State::OPEN, State::CLOSED], true))
            ->action(function (ServiceOrder $record, array $data): void {
                Log::debug('TransferServiceOrderAction (Filament): transferindo OS', [
                    'metodo' => __METHOD__.'@'.__LINE__,
                    'service_order_id' => $record->id,
                    'target_customer_id' => $data['customer_id'] ?? null,
                    'target_equipment_id' => $data['equipment_id'] ?? null,
                    'user_id' => Auth::id(),
                ]);

                $service = app(ServiceOrderService::class);
                $transferred = $service->transfer(
                    $record,
                    (int) $data['customer_id'],
                    isset($data['equipment_id']) ? (int) $data['equipment_id'] : null,
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
}
