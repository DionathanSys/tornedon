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

class EditCompanyPartner extends EditRecord
{
    protected static string $resource = CompanyPartnerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {

        ds($data)->label('Dados recuperados do DB para form - '. __METHOD__);

        $partner                                        = Partner::find($this->record->partner_id);
        $data['name']                                   = $partner->name;
        $data['document_type']                          = $partner->document_type;
        $data['document_number']                        = $partner->document_number;
        $data['state_tax_id']                           = $partner->state_tax_id;
        $data['municipal_tax_id']                       = $partner->municipal_tax_id;
        $data['state_tax_indicator']                    = $partner->state_tax_indicator;
        $data['company_partner']['type']                = $data['type'];
        $data['company_partner']['invoice_threshold']   = $data['invoice_threshold'];
        $data['company_partner']['is_active']           = $data['is_active'];
        
        ds($data)->label('Dados processados para form - '. __METHOD__);

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
            notify::error(message: $service->getMessage());
            $this->halt();
        }

        ds($result)->label("Record atualizado - ".__METHOD__);

        return $result;
    }
}
