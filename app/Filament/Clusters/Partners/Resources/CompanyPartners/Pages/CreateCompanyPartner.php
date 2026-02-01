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
use Illuminate\Support\Facades\DB;

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
        return DB::transaction(function () use ($data) {
            $service = new PartnerService();
            $partner = $service->findOrCreatePartner($data);

            if ($service->hasError() || $partner === null) {
                notify::error(
                    message: $service->getMessageUser(),
                    errorCode: $service->getErrorCode()
                );
                $this->halt();
            }

            $result = $service->associatePartnerCompany(
                $partner->id,
                $data['company_id'],
                $data['company_partner']
            );

            if ($service->hasError()) {
                notify::error(
                    message: $service->getMessageUser(),
                    errorCode: $service->getErrorCode()
                );
                $this->halt();
            }

            return $result;
        });

    }
}
