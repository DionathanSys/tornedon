<?php

namespace App\Filament\Clusters\Partners\Resources\Addresses\Actions;

use App\Filament\Clusters\Partners\Resources\Addresses\Components\AddressComponent;
use App\Models\Address;
use App\Services\Address\AddressService;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use App\Notification\NotifyService as notify;
use Filament\Actions\Action;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Log;

final class EditAddressAction
{
    public static function make(): Action
    {
        return Action::make('edit_address')
            ->label('Editar')
            ->icon(Heroicon::PencilSquare)
            ->modal()
            ->fillForm(function (array $arguments): array {
                $addressId = $arguments['address_id'] ?? null;

                if (!$addressId) {
                    return [];
                }

                $address = Address::find($addressId);

                if (!$address) {
                    return [];
                }

                return [
                    'street' => $address->street,
                    'number' => $address->number,
                    'complement' => $address->complement,
                    'neighborhood' => $address->neighborhood,
                    'city' => $address->city,
                    'state' => $address->state,
                    'country' => $address->country,
                    'postal_code' => $address->postal_code,
                    'city_code' => $address->city_code,
                ];
            })
            ->schema(function (Schema $schema): Schema {
                return $schema
                    ->columns([
                        'sm' => 1,
                        'md' => 4,
                        'lg' => 8,
                    ])
                    ->components(AddressComponent::make());
            })
            ->action(function (Action $action, array $data, array $arguments) {
                $addressId = $arguments['address_id'] ?? null;

                if (!$addressId) {
                    notify::error(message: 'Endereço não identificado. Não foi possível editar.');
                    $action->halt();
                    return;
                }

                $address = Address::find($addressId);

                if (!$address) {
                    notify::error(message: 'Endereço não encontrado. Não foi possível editar.');
                    $action->halt();
                    return;
                }

                Log::debug('Iniciando atualização de endereço', [
                    'metodo'             => __METHOD__ . '@' . __LINE__,
                    'address_id'         => $addressId,
                    'company_partner_id' => $address->company_partner_id,
                    'data'               => $data,
                ]);

                $service = new AddressService();
                $result = $service->update($address, $data, Auth::id());

                if ($service->hasError()) {
                    notify::error(message: $service->getMessageUser());
                    $action->halt();
                    return;
                }

                notify::success(message: $service->getMessageUser());

                return $result;
            })
            ->after(function (Action $action) {
                $record = $action->getRecord();
                if ($record) {
                    $record->refresh();
                    $record->load('addresses');
                }
                
                $livewire = $action->getLivewire();
                if ($livewire && method_exists($livewire, 'refreshFormData')) {
                    $livewire->refreshFormData(['addresses']);
                }
            })
            ->modalWidth('5xl')
            ->modalSubmitActionLabel('Atualizar')
            ->slideOver();
    }
}
