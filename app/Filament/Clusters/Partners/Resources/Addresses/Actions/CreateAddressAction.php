<?php

namespace App\Filament\Clusters\Partners\Resources\Addresses\Actions;

use App\Filament\Clusters\Partners\Resources\Addresses\AddressResource;
use App\Models\Address;
use App\Services\Address\AddressService;
use App\Services\Partner\CompanyPartnerService;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use App\Notification\NotifyService as notify;
use Filament\Actions\Action;
use Filament\Schemas\Components\Utilities\Get;
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
            ->schema(fn(Schema $schema): Schema => AddressResource::form($schema))
            ->action(function (Get $get, Action $action, array $data, array $arguments) {

                Log::debug(__METHOD__ . '@' . __LINE__, [
                    'message' => 'Iniciando criação de novo endereço para Parceiro',
                    'data'    => $data,
                    'args'    => $arguments,
                    'partner_id' => $get('partner_id'),
                ]);
                
                $tenant     = Filament::getTenant();
                $companyId  = $tenant->id;
                $partnerId  = $data['partner_id'] ?? 0;
                $company_partner_id = CompanyPartnerService::getIdCompanyPartner($partnerId);

                if(!$company_partner_id) {
                    notify::error(message: 'Vínculo entre Empresa e Parceiro não encontrado. Não é possível cadastrar o endereço.');
                    $action->halt();
                }

                $service = new AddressService();
                $result = $service->create($company_partner_id, $companyId, $partnerId, $data, Auth::id());

                if ($service->hasError()) {
                    notify::error(message: $service->getMessageUser());
                    $action->halt();
                }

                notify::success(message: $service->getMessageUser());

                if ($arguments['another'] ?? false) {
                    $action->fillForm([
                        'partner_id' => $data['partner_id'],
                    ]);
                    $action->halt();
                }

                return $result;
            })
            ->extraModalFooterActions(fn(Action $action): array => [
                $action->makeModalSubmitAction('createAnother', arguments: ['another' => true])
                    ->label('Salvar e criar outro')
                    ->color('secondary'),
            ]);
    }
}
