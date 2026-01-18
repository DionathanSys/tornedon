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
use Filament\Schemas\Schema;

final class CreateAddressAction
{
    public static function make(): Action
    {
        return Action::make('create_address')
            ->label('Endereço')
            ->icon(Heroicon::Plus)
            ->modal()
            ->schema(fn(Schema $schema): Schema => AddressResource::form($schema))
            ->action(function (Action $action, array $data, array $arguments) {

                $tenant     = Filament::getTenant();
                $companyId  = $tenant->id;
                $partnerId  = $data['partner_id'] ?? 0;
                $company_partner_id = CompanyPartnerService::getIdCompanyPartner($partnerId);

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
                $action->makeModalSubmitAction('createAnother', arguments: ['another' => true]),
            ]);
    }
}
