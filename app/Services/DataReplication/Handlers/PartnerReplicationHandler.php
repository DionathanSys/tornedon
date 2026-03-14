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

        Log::info('Starting partner replication', [
            'partner_id' => $partner->id,
            'target_companies' => $targetCompanyIds,
        ]);

        foreach ($targetCompanyIds as $companyId) {
            try {
                $this->replicateToCompany($partner, $companyId);
                $result['successful'][] = [
                    'company_id' => $companyId,
                    'partner_id' => $partner->id,
                ];
                
                Log::info('Partner replicated to company', [
                    'partner_id' => $partner->id,
                    'company_id' => $companyId,
                ]);
            } catch (\Exception $e) {
                $result['failed'][] = [
                    'company_id' => $companyId,
                    'partner_id' => $partner->id,
                    'error' => $e->getMessage(),
                ];
                
                Log::error('Failed to replicate partner to company', [
                    'partner_id' => $partner->id,
                    'company_id' => $companyId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        return $result;
    }

    /**
     * Replica para uma empresa específica COM validação de empresa origem
     */
    public function replicateToCompanyFromSource(
        Partner $partner,
        int $companyId,
        int $sourceCompanyId
    ): void {
        // Verificar se já existe CompanyPartner
        $existing = CompanyPartner::where('company_id', $companyId)
            ->where('partner_id', $partner->id)
            ->first();

        if ($existing) {
            throw new \DomainException(
                "Partner já está vinculado a esta empresa (company_id: {$companyId})."
            );
        }

        // Obter CompanyPartner ESPECÍFICO da empresa de origem (validação de segurança)
        $sourceCompanyPartner = CompanyPartner::where('partner_id', $partner->id)
            ->where('company_id', $sourceCompanyId)
            ->first();

        if (!$sourceCompanyPartner) {
            Log::error('Source CompanyPartner not found or unauthorized', [
                'partner_id' => $partner->id,
                'source_company_id' => $sourceCompanyId,
                'target_company_id' => $companyId,
            ]);
            
            throw new \DomainException(
                "Partner não está vinculado à empresa de origem ou acesso não permitido (company_id: {$sourceCompanyId})."
            );
        }

        $this->performReplication($partner, $companyId, $sourceCompanyPartner);
    }

    /**
     * Replica para uma empresa específica (sem validação de origem)
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

        // Se não houver CompanyPartner de origem, usar defaults
        if (!$sourceCompanyPartner) {
            Log::warning('No source CompanyPartner found for replication', [
                'partner_id' => $partner->id,
                'target_company_id' => $companyId,
            ]);
            
            throw new \DomainException(
                "Nenhum vínculo de partner encontrado para copiar dados. Verifique se o partner está vinculado a uma empresa."
            );
        }

        $this->performReplication($partner, $companyId, $sourceCompanyPartner);
    }

    /**
     * Executa a replicação com dados validados
     */
    private function performReplication(
        Partner $partner,
        int $companyId,
        CompanyPartner $sourceCompanyPartner
    ): void {

        // Preparar dados para associação
        $companyPartnerData = [
            'type' => $sourceCompanyPartner->type ?? [],
            'invoice_threshold' => $sourceCompanyPartner->invoice_threshold ?? 0,
            'is_active' => $sourceCompanyPartner->is_active ?? true,
        ];

        // Validar que type tem pelo menos um item
        if (empty($companyPartnerData['type'])) {
            throw new \DomainException(
                "Tipo de vínculo não definido no partner de origem. Verifique os dados."
            );
        }

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
        $this->replicateAddresses($sourceCompanyPartner, $newCompanyPartner);

        // Replicar contatos
        $this->replicateContacts($sourceCompanyPartner, $newCompanyPartner);
    }

    /**
     * Método wrapper para suportar replicação com validação de origem
     */
    public function handleWithSource(Partner $partner, array $targetCompanyIds, ?int $sourceCompanyId = null): array
    {
        $result = [
            'successful' => [],
            'failed' => [],
        ];

        Log::info('Starting partner replication from source', [
            'partner_id' => $partner->id,
            'source_company_id' => $sourceCompanyId,
            'target_companies' => $targetCompanyIds,
        ]);

        foreach ($targetCompanyIds as $companyId) {
            // Evitar replicar para a mesma empresa de origem
            if ($sourceCompanyId && $companyId === $sourceCompanyId) {
                Log::debug('Skipping replication to source company', [
                    'partner_id' => $partner->id,
                    'company_id' => $companyId,
                ]);
                continue;
            }

            try {
                if ($sourceCompanyId) {
                    $this->replicateToCompanyFromSource($partner, $companyId, $sourceCompanyId);
                } else {
                    $this->replicateToCompany($partner, $companyId);
                }

                $result['successful'][] = [
                    'company_id' => $companyId,
                    'partner_id' => $partner->id,
                ];
                
                Log::info('Partner replicated to company', [
                    'partner_id' => $partner->id,
                    'company_id' => $companyId,
                ]);
            } catch (\Exception $e) {
                $result['failed'][] = [
                    'company_id' => $companyId,
                    'partner_id' => $partner->id,
                    'error' => $e->getMessage(),
                ];
                
                Log::error('Failed to replicate partner to company', [
                    'partner_id' => $partner->id,
                    'company_id' => $companyId,
                    'source_company_id' => $sourceCompanyId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        return $result;
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
