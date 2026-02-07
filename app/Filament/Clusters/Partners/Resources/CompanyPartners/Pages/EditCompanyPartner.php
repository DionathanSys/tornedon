<?php

namespace App\Filament\Clusters\Partners\Resources\CompanyPartners\Pages;

use App\Filament\Clusters\Partners\Resources\CompanyPartners\CompanyPartnerResource;
use App\Models\Partner;
use App\Services\Partner\CompanyPartnerService;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use App\Notification\NotifyService as notify;
use App\Services\Partner\PartnerService;
use Illuminate\Support\Facades\Log;

class EditCompanyPartner extends EditRecord
{
    protected static string $resource = CompanyPartnerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make()
                ->visible(false),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $partner                                        = (new PartnerService())->getPartnerById($this->record->partner_id);
        $data['partner_exists']                         = true;
        $data['partner_id']                             = $partner->id;
        $data['name']                                   = $partner->name;
        $data['document_type']                          = $partner->document_type;
        $data['document_number']                        = $partner->document_number;
        $data['state_tax_id']                           = $partner->state_tax_id;
        $data['municipal_tax_id']                       = $partner->municipal_tax_id;
        $data['state_tax_indicator']                    = $partner->state_tax_indicator;
        $data['company_partner']['type']                = $data['type'];
        $data['company_partner']['invoice_threshold']   = $data['invoice_threshold'];
        $data['company_partner']['is_active']           = $data['is_active'];
        
        Log::debug('Mutate Form Data Before Fill:', $data);
        
        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data = $data['company_partner'];

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $service = new CompanyPartnerService();
        $result = $service->update($record, $data);

        if($service->hasError()){ 
            notify::error(message: $service->getMessageUser());
            $this->halt();
        }

        return $result;
    }
}
