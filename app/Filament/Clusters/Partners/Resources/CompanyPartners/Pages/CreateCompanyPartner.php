<?php

namespace App\Filament\Clusters\Partners\Resources\CompanyPartners\Pages;

use App\Filament\Clusters\Partners\Resources\CompanyPartners\CompanyPartnerResource;
use App\Services\Partner\PartnerService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Notification\NotifyService as notify;

class CreateCompanyPartner extends CreateRecord
{
    protected static string $resource = CompanyPartnerResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $tenant = Filament::getTenant();

        $data['created_by'] = Auth::id();
        $data['company_id'] = $tenant->id;

        return $data;
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Parceiro cadastrado';
    }

    protected function handleRecordCreation(array $data): Model
    {
        $service = new PartnerService();
        $partner = $service->createPartner($data);

        if($service->hasError()){
            notify::error();
            $this->halt();
        }

        $result = $service->associatePartnerCompany($partner->id, $data['company_id'], $data['company_partner']);

        if($service->hasError()){
            notify::error();
            $this->halt();
        }
        
        return $result;
    }
}
