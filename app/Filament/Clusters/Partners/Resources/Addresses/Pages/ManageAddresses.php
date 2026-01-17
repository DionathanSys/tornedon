<?php

namespace App\Filament\Clusters\Partners\Resources\Addresses\Pages;

use App\Filament\Clusters\Partners\Resources\Addresses\AddressResource;
use App\Services\Address\AddressService;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use App\Notification\NotifyService as notify;
use Filament\Facades\Filament;

class ManageAddresses extends ManageRecords
{
    protected static string $resource = AddressResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Endereço')
                ->icon(Heroicon::Plus)
                ->action(function(CreateAction $action, array $data){

                    $tenant = Filament::getTenant();
                    $companyId = $tenant->id;
                    $partnerId = $data['partner_id'];
                
                    $service = new AddressService();
                    $result = $service->create($companyId, $partnerId, $data, Auth::id());

                    ds($service->hasError())->label('Service has error?');

                    if($service->hasError()) {
                        notify::error(message: $service->getMessageUser());
                        $action->halt();
                    }

                    ds($result);

                    notify::success(message: $service->getMessageUser());

                    if($data['open-record-after-creation'] ?? false) {
                        $action->redirect(
                            AddressResource::getUrl('edit', ['record' => $result->id])
                        );
                    }

                }),
        ];
    }
}
