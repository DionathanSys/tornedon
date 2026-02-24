<?php

namespace App\Filament\Clusters\Partners\Resources\Addresses\Actions;

use App\Filament\Clusters\Partners\Resources\Addresses\AddressResource;
use App\Filament\Clusters\Partners\Resources\Addresses\Components\AddressComponent;
use App\Filament\Clusters\Partners\Resources\Addresses\Components\AddressComponentFull;
use App\Models\Address;
use App\Services\Address\AddressService;
use App\Services\Partner\CompanyPartnerService;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use App\Notification\NotifyService as notify;
use Filament\Actions\Action;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Log;

final class CreateAddressAction
{
    public static function make(): Action
    {
        return Action::make('create_address')
            ->label('Endereço')
            ->icon(Heroicon::Plus)
            ->badge()
            ->modal()
            ->schema(function (Schema $schema, Action $action): Schema {
                return $schema
                    ->columns([
                        'sm' => 1,
                        'md' => 4,
                        'lg' => 8,
                    ])
                    ->components(AddressComponent::make());
            })
            ->action(function (Action $action, array $data, array $arguments) {
                $record = $action->getRecord();

                $service = new AddressService();
                $result = $service->create($record->id, $data, Auth::id());

                if ($service->hasError()) {
                    notify::error(message: $service->getMessageUser());
                    $action->halt();
                }

                notify::success(message: $service->getMessageUser());

                if ($arguments['another'] ?? false) {
                    $action->fillForm([]);
                    $action->halt();
                }

                return $result;
            })
            ->after(function (Action $action) {
                // Recarregar o relacionamento addresses no record atual
                $record = $action->getRecord();
                if ($record) {
                    $record->refresh();
                    $record->load('addresses');
                }

                // Disparar evento para atualizar o Livewire
                $livewire = $action->getLivewire();
                if ($livewire && method_exists($livewire, 'refreshFormData')) {
                    $livewire->refreshFormData(['addresses']);
                }
            })
            ->extraModalFooterActions(fn(Action $action): array => [
                $action->makeModalSubmitAction('createAnother', arguments: ['another' => true])
                    ->label('Salvar e criar outro')
                    ->color('secondary'),
            ])
            ->modalSubmitAction(fn(Action $action) => $action->label('Salvar'));
    }
}
