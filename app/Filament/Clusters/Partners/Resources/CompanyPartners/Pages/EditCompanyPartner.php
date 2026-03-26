<?php

namespace App\Filament\Clusters\Partners\Resources\CompanyPartners\Pages;

use App\Filament\Clusters\Partners\Resources\CompanyPartners\CompanyPartnerResource;
use App\Filament\Clusters\Partners\Resources\Equipments\EquipmentResource;
use App\Filament\Shared\Actions\ReplicateToCompaniesAction;
use App\Models\CompanyPartner;
use App\Notification\NotifyService as notify;
use App\Services\Partner\CompanyPartnerService;
use App\Services\Partner\PartnerService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class EditCompanyPartner extends EditRecord
{
    protected static string $resource = CompanyPartnerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                ReplicateToCompaniesAction::make('replicate'),
            ])->size(Size::Small)->button(),
            ActionGroup::make([
                Action::make('new-partner')
                    ->label('Parceiro')
                    ->url(CompanyPartnerResource::getUrl('create'))
                    ->icon(Heroicon::Plus)
                    ->color('primary')
                    ->size(Size::Small),
                Action::make('manager_equipments')
                    ->label('Equipamentos')
                    ->url(fn (CompanyPartner $record) => EquipmentResource::getUrl(
                        'index',
                        [
                            'filters' => [
                                'owner_id' => [
                                    'value' => $record->partner_id,
                                ],
                            ],
                        ]
                    ))
                    ->openUrlInNewTab()
                    ->icon(Heroicon::Wrench)
                    ->color('primary')
                    ->size(Size::Small),
            ])->buttonGroup(),
            DeleteAction::make()
                ->visible(false),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $partner = (new PartnerService())->getPartnerById($this->record->partner_id);
        $data['has_valid_address'] = $this->hasValidAddress($this->record);
        $data['partner_exists'] = true;
        $data['partner_id'] = $partner->id;
        $data['name'] = $partner->name;
        $data['document_type'] = $partner->document_type;
        $data['document_number'] = $partner->document_number;
        $data['state_tax_id'] = $partner->state_tax_id;
        $data['municipal_tax_id'] = $partner->municipal_tax_id;
        $data['state_tax_indicator'] = $partner->state_tax_indicator;
        $data['company_partner']['type'] = $data['type'];
        $data['company_partner']['invoice_threshold'] = $data['invoice_threshold'];
        $data['company_partner']['customer_discount_percentage'] = $data['customer_discount_percentage'] ?? 0;
        $data['company_partner']['is_active'] = $data['is_active'];
        $data['company_partner']['notify_service_order_closed'] = $data['notify_service_order_closed'] ?? false;
        $data['company_partner']['notify_requisition_closed'] = $data['notify_requisition_closed'] ?? false;
        $data['company_partner']['notify_fiscal_document_confirmed'] = $data['notify_fiscal_document_confirmed'] ?? false;
        $data['company_partner']['email_to_override'] = $data['email_to_override'] ?? null;
        $data['company_partner']['email_cc_override'] = $data['email_cc_override'] ?? null;
        $data['company_partner']['email_bcc_override'] = $data['email_bcc_override'] ?? null;

        return $data;
    }

    private function hasValidAddress(CompanyPartner $companyPartner): bool
    {
        $AddressValid = $companyPartner->addresses()
            ->get()
            ->contains(function ($address): bool {
                return filled($address->street)
                    && filled($address->number)
                    && filled($address->city)
                    && filled($address->city_code)
                    && filled($address->state)
                    && filled($address->country)
                    && preg_match('/^\d{5}-?\d{3}$/', (string) $address->postal_code) === 1;
            });

        Log::debug('Address Valid:', [
            'valid?' => $AddressValid,
            'companyPartner' => $companyPartner,
            'addresses' => $companyPartner->addresses,
        ]);
        return $AddressValid;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        Log::debug('Mutate Form Data Before Save - Received Data:', $data);

        $partnerData = [
            'name' => $data['name'] ?? null,
            'document_type' => $data['document_type'] ?? null,
            'document_number' => $data['document_number'] ?? null,
            'state_tax_id' => $data['state_tax_id'] ?? null,
            'municipal_tax_id' => $data['municipal_tax_id'] ?? null,
            'state_tax_indicator' => $data['state_tax_indicator'] ?? null,
            'updated_by' => Auth::id(),
        ];

        if (! empty($partnerData['name'])) {
            $partnerService = new PartnerService();
            $partner = $partnerService->getPartnerById($this->record->partner_id);

            if ($partner) {
                $partnerData['id'] = $partner->id;
                Log::debug('Updating Partner with data:', $partnerData);

                $partnerService->editPartner(Auth::id(), $partner, $partnerData);

                if ($partnerService->hasError()) {
                    notify::error(message: $partnerService->getMessageUser());
                    $this->halt();
                }
            }
        }

        $companyPartnerData = $data['company_partner'] ?? [];
        Log::debug('Returning CompanyPartner data:', $companyPartnerData);

        return $companyPartnerData;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $service = new CompanyPartnerService();
        $result = $service->update($record, $data);

        if ($service->hasError()) {
            notify::error(message: $service->getMessageUser());
            $this->halt();
        }

        return $result;
    }
}
