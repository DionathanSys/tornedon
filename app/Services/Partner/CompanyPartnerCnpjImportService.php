<?php

namespace App\Services\Partner;

use App\Domain\DTO\Cnpj\CnpjVO;
use App\Models\CompanyPartner;
use App\Services\Address\AddressService;
use App\Services\Cnpj\CnpjConsultationService;
use App\Services\Contact\ContactService;
use App\Traits\HandlesServiceResponse;
use Illuminate\Support\Facades\Log;

class CompanyPartnerCnpjImportService
{
    use HandlesServiceResponse;

    public function import(int $companyPartnerId, int $userId): bool
    {
        $this->resetResponse();

        $companyPartner = CompanyPartner::query()
            ->with('partner')
            ->find($companyPartnerId);

        if (! $companyPartner) {
            $this->setError('Vínculo empresa-parceiro não encontrado.', [], 404);
            return false;
        }

        $partner = $companyPartner->partner;

        if (! $partner || $partner->document_type !== 'cnpj') {
            $this->setError('O parceiro não possui CNPJ cadastrado.');
            return false;
        }

        $cnpjService = app(CnpjConsultationService::class);
        $vo = $cnpjService->consult($partner->document_number, [
            'company_id' => $companyPartner->company_id,
            'user_id' => $userId,
            'source' => 'company_partner_cnpj_import',
        ]);

        if (! $vo) {
            $this->setError(
                $cnpjService->getMessage() ?: 'Erro ao consultar CNPJ.',
                $cnpjService->getErrors(),
                $cnpjService->getStatus()
            );

            Log::warning(__METHOD__ . '@' . __LINE__, [
                'message' => 'Falha ao consultar CNPJ para importação de parceiro',
                'company_partner_id' => $companyPartnerId,
                'partner_id' => $partner->id,
                'document_number' => $partner->document_number,
                'errors' => $cnpjService->getErrors(),
            ]);

            return false;
        }

        $addressService = app(AddressService::class);
        $address = $addressService->create($companyPartner->id, self::mapAddressFromVo($vo), $userId);

        if (! $address) {
            $this->setError(
                $addressService->getMessageUser() ?: 'Erro ao importar endereço.',
                $addressService->getErrors(),
                $addressService->getStatus()
            );

            return false;
        }

        $contactService = app(ContactService::class);
        $contact = $contactService->create($companyPartner->id, self::mapContactFromVo($vo), $userId);

        if (! $contact) {
            $this->setError(
                $contactService->getMessageUser() ?: 'Erro ao importar contato.',
                $contactService->getErrors(),
                $contactService->getStatus()
            );

            return false;
        }

        $this->setSuccess("Endereço e contato de {$vo->companyName} importados com sucesso.");

        Log::info(__METHOD__ . '@' . __LINE__, [
            'message' => 'Importação de dados por CNPJ concluída',
            'company_partner_id' => $companyPartnerId,
            'partner_id' => $partner->id,
            'user_id' => $userId,
            'company_name' => $vo->companyName,
            'address_id' => $address->id,
            'contact_id' => $contact->id,
        ]);

        return true;
    }

    public static function mapContactFromVo(CnpjVO $vo): array
    {
        return [
            'email' => $vo->email,
            'phone' => $vo->phone,
            'mobile' => null,
            'notify' => false,
            'is_active' => true,
        ];
    }

    public static function mapAddressFromVo(CnpjVO $vo): array
    {
        $address = $vo->address;

        return [
            'street' => $address->street ?? '',
            'number' => $address->number ?? 'S/N',
            'complement' => $address->details,
            'neighborhood' => $address->district,
            'city' => $address->city ?? '',
            'state' => $address->state ?? '',
            'postal_code' => self::formatPostalCode($address->zip),
            'country' => 'Brasil',
            'city_code' => $address->municipalityCode,
        ];
    }

    public static function formatPostalCode(?string $zip): string
    {
        if (! $zip) {
            return '';
        }

        $digits = preg_replace('/\D/', '', $zip);

        if (strlen($digits) === 8) {
            return substr($digits, 0, 5) . '-' . substr($digits, 5, 3);
        }

        return $digits;
    }
}
