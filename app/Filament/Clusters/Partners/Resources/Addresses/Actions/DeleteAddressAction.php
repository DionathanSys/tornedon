<?php

namespace App\Filament\Clusters\Partners\Resources\Addresses\Actions;

use App\Models\Address;
use App\Services\Address\AddressService;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use App\Notification\NotifyService as notify;
use Filament\Actions\Action;
use Illuminate\Support\Facades\Log;

final class DeleteAddressAction
{
    public static function make(): Action
    {
        return Action::make('delete_address')
            ->label('Excluir')
            ->icon(Heroicon::Trash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Excluir Endereço')
            ->modalDescription('Tem certeza que deseja excluir este endereço? Esta ação não pode ser desfeita.')
            ->modalSubmitActionLabel('Sim, excluir')
            ->action(function (Action $action, array $arguments) {
                $addressId = $arguments['address_id'] ?? null;

                if (!$addressId) {
                    notify::error(message: 'Endereço não identificado. Não foi possível excluir.');
                    $action->halt();
                    return;
                }

                $address = Address::find($addressId);

                if (!$address) {
                    notify::error(message: 'Endereço não encontrado. Não foi possível excluir.');
                    $action->halt();
                    return;
                }

                Log::debug('Iniciando exclusão de endereço', [
                    'metodo'             => __METHOD__ . '@' . __LINE__,
                    'address_id'         => $addressId,
                    'company_partner_id' => $address->company_partner_id,
                ]);

                $service = new AddressService();
                $result = $service->delete($address, Auth::id());

                if ($service->hasError()) {
                    notify::error(message: $service->getMessageUser());
                    $action->halt();
                    return;
                }

                notify::success(message: $service->getMessageUser());
            })
            ->successNotificationTitle('Endereço excluído com sucesso!')
            ->after(function (Action $action) {
                $livewire = $action->getLivewire();
                
                if ($livewire && method_exists($livewire, 'refreshFormData')) {
                    $livewire->refreshFormData(['addresses']);
                }
            });
    }
}
