<?php

namespace App\Filament\Clusters\Partners\Resources\CompanyPartners\Pages;

use App\Filament\Clusters\Partners\Resources\CompanyPartners\CompanyPartnerResource;
use App\Models\Partner;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

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
        $data = parent::mutateFormDataBeforeFill($data);

        $partner                    = Partner::find($this->record->partner_id);
        $data['name']               = $partner->name;
        $data['document_type']      = $partner->document_type;
        $data['document_number']    = $partner->document_number;
        $data['state_tax_id']       = $partner->state_tax_id;
        $data['municipal_tax_id']   = $partner->municipal_tax_id;
        $data['state_tax_indicator'] = $partner->state_tax_indicator;

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data = $data['company_partner'];

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $record->update($data);

        return $record;
    }
}
