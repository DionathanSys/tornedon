<?php

namespace App\Services\DataReplication\Handlers;

use App\Models\Address;
use App\Models\CompanyPartner;
use App\Models\Contact;
use App\Models\Partner;
use App\Services\Partner\PartnerService;
use App\Services\Address\AddressService;
use App\Services\Contact\ContactService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class PartnerReplicationHandler
{
    /**
     * Handler para replicação de Partners
     *
     * Replica:
     * 1. Partner (se não existir na empresa alvo)
     * 2. CompanyPartner com dados específicos da empresa
     * 3. Endereços vinculados
     * 4. Contatos vinculados
     */
    public function handle(Partner $partner, array $targetCompanyIds): array
    {
        $result = [
            'successful' => [],
            'failed' => [],
        ];

        foreach ($targetCompanyIds as $companyId) {
            try {
                $this->replicateToCompany($partner, $companyId);
                $result['successful'][] = [
                    'company_id' => $companyId,
                    'partner_id' => $partner->id,
                ];
            } catch (\Exception $e) {
                $result['failed'][] = [
                    'company_id' => $companyId,
                    'partner_id' => $partner->id,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $result;
    }

    /**
     * Replica para uma empresa específica
     */
    private function replicateToCompany(Partner $partner, int $companyId): void
    {
        // Verificar se já existe CompanyPartner
        $existing = CompanyPartner::where('company_id', $companyId)
            ->where('partner_id', $partner->id)
            ->first();

        if ($existing) {
            throw new \DomainException(
                "Partner já está vinculado a esta empresa (company_id: {$companyId})."
            );
        }

        // Encontrar CompanyPartner original para copiar dados
        $sourceCompanyPartner = CompanyPartner::where('partner_id', $partner->id)
            ->first();

        // Preparar dados para associação
        $companyPartnerData = [
            'type' => $sourceCompanyPartner?->type ?? [],
            'invoice_threshold' => $sourceCompanyPartner?->invoice_threshold ?? 0,
            'is_active' => $sourceCompanyPartner?->is_active ?? true,
        ];

        // Usar PartnerService para associar Partner com Company
        $partnerService = app(PartnerService::class);
        $newCompanyPartner = $partnerService->associatePartnerCompany(
            $partner->id,
            $companyId,
            $companyPartnerData
        );

        if (!$newCompanyPartner) {
            throw new \DomainException(
                "Falha ao associar Partner com Company: " . $partnerService->getMessageUser()
            );
        }

        // Replicar endereços
        if ($sourceCompanyPartner) {
            $this->replicateAddresses($sourceCompanyPartner, $newCompanyPartner);

            // Replicar contatos
            $this->replicateContacts($sourceCompanyPartner, $newCompanyPartner);
        }
    }

    /**
     * Replica endereços de um CompanyPartner para outro
     */
    private function replicateAddresses(CompanyPartner $source, CompanyPartner $target): void
    {
        $sourceAddresses = Address::where('company_partner_id', $source->id)
            ->get();

        $addressService = app(AddressService::class);
        $userId = Auth::id() ?? 0;

        foreach ($sourceAddresses as $address) {
            $addressData = [
                'type' => $address->type,
                'street' => $address->street,
                'number' => $address->number,
                'complement' => $address->complement,
                'neighborhood' => $address->neighborhood,
                'city' => $address->city,
                'state' => $address->state,
                'postal_code' => $address->postal_code,
                'country' => $address->country,
                'is_primary' => $address->is_primary,
            ];

            $createdAddress = $addressService->create(
                $target->id,
                $addressData,
                $userId
            );

            if (!$createdAddress) {
                Log::warning('Failed to replicate address', [
                    'source_address_id' => $address->id,
                    'target_company_partner_id' => $target->id,
                    'error' => $addressService->getMessageUser(),
                ]);
            }
        }
    }

    /**
     * Replica contatos de um CompanyPartner para outro
     */
    private function replicateContacts(CompanyPartner $source, CompanyPartner $target): void
    {
        $sourceContacts = Contact::where('company_partner_id', $source->id)
            ->get();

        $contactService = app(ContactService::class);
        $userId = Auth::id() ?? 0;

        foreach ($sourceContacts as $contact) {
            $contactData = [
                'name' => $contact->name,
                'email' => $contact->email,
                'phone' => $contact->phone,
                'document_number' => $contact->document_number,
                'is_primary' => $contact->is_primary,
            ];

            $createdContact = $contactService->create(
                $target->id,
                $contactData,
                $userId
            );

            if (!$createdContact) {
                Log::warning('Failed to replicate contact', [
                    'source_contact_id' => $contact->id,
                    'target_company_partner_id' => $target->id,
                    'error' => $contactService->getMessageUser(),
                ]);
            }
        }
    }
}
