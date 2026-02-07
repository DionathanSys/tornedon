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
            ->modal()
            ->schema(function (Schema $schema, Action $action): Schema {
                // Detecta o contexto: se tem record, está dentro do form do parceiro
                $record = $action->getRecord();

                if ($record && $record instanceof \App\Models\CompanyPartner) {
                    // Contexto: dentro do form do parceiro - usa AddressComponent (sem select de parceiro)
                    return $schema
                        ->columns([
                            'sm' => 1,
                            'md' => 4,
                            'lg' => 8,
                        ])
                        ->components(AddressComponent::make());
                }
                
                // Contexto: index de endereços - usa AddressComponentFull (com select de parceiro)
                return AddressResource::form($schema);
            })
            ->action(function (Action $action, array $data, array $arguments) {
                $record = $action->getRecord();
                
                // Determina o partner_id baseado no contexto
                if ($record && $record instanceof \App\Models\CompanyPartner) {
                    // Está dentro do form do parceiro - usa o ID do record
                    $partnerId = $record->partner_id;
                } else {
                    // Está no index de endereços - usa o partner_id do formulário
                    $partnerId = $data['partner_id'] ?? 0;
                }

                Log::debug(__METHOD__ . '@' . __LINE__, [
                    'message' => 'Iniciando criação de novo endereço para Parceiro',
                    'data'    => $data,
                    'args'    => $arguments,
                    'partner_id' => $partnerId,
                    'context' => $record ? 'partner_form' : 'addresses_index',
                ]);
                
                //TODO Remover caso seja removido o Resource de Address
                $company_partner_id = CompanyPartnerService::getIdCompanyPartner($partnerId);

                if(!$company_partner_id) {
                    notify::error(message: 'Vínculo entre Empresa e Parceiro não encontrado. Não é possível cadastrar o endereço.');
                    $action->halt();
                }

                $service = new AddressService();
                $result = $service->create($company_partner_id, $data, Auth::id());

                if ($service->hasError()) {
                    notify::error(message: $service->getMessageUser());
                    $action->halt();
                }

                notify::success(message: $service->getMessageUser());

                if ($arguments['another'] ?? false) {
                    // Se está no contexto do index, mantém o partner_id selecionado
                    if (!($record && $record instanceof \App\Models\CompanyPartner)) {
                        $action->fillForm([
                            'partner_id' => $data['partner_id'] ?? null,
                        ]);
                    }
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
