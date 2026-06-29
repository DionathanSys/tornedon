<?php

namespace App\Domain\DTO\Cnpj;

class CnpjVO
{
    /**
     * @param  CnpjActivityVO[]     $sideActivities
     * @param  CnpjRegistrationVO[] $registrations
     */
    public function __construct(
        public readonly string $taxId,
        public readonly string $companyName,
        public readonly ?string $tradeName,
        public readonly ?string $founded,
        public readonly bool $head,
        public readonly ?string $statusText,
        public readonly ?string $statusDate,
        public readonly ?string $natureText,
        public readonly ?float $equity,
        public readonly bool $simplesOptant,
        public readonly bool $simeiOptant,
        public readonly CnpjAddressVO $address,
        public readonly ?CnpjActivityVO $mainActivity,
        public readonly array $sideActivities,
        public readonly array $registrations,
        public readonly ?string $phone,
        public readonly ?string $email,
    ) {}

    public static function fromApiResponse(array $data): self
    {
        $sideActivities = array_map(
            fn(array $item) => CnpjActivityVO::fromArray($item),
            $data['sideActivities'] ?? [],
        );

        $registrations = array_map(
            fn(array $item) => CnpjRegistrationVO::fromArray($item),
            $data['registrations'] ?? [],
        );

        $phone = self::extractPhone($data['phones'] ?? []);
        $email = self::extractEmail($data['emails'] ?? []);

        return new self(
            taxId: $data['taxId'],
            companyName: $data['company']['name'] ?? '',
            tradeName: $data['alias'] ?? null,
            founded: $data['founded'] ?? null,
            head: $data['head'] ?? false,
            statusText: $data['status']['text'] ?? null,
            statusDate: $data['statusDate'] ?? null,
            natureText: $data['company']['nature']['text'] ?? null,
            equity: $data['company']['equity'] ?? null,
            simplesOptant: $data['company']['simples']['optant'] ?? false,
            simeiOptant: $data['company']['simei']['optant'] ?? false,
            address: CnpjAddressVO::fromArray($data['address'] ?? []),
            mainActivity: isset($data['mainActivity'])
                ? CnpjActivityVO::fromArray($data['mainActivity'])
                : null,
            sideActivities: $sideActivities,
            registrations: $registrations,
            phone: $phone,
            email: $email,
        );
    }

    public function toArray(): array
    {
        $mainRegistration = $this->getMainStateRegistration();

        return [
            'tax_id' => $this->taxId,
            'company_name' => $this->companyName,
            'trade_name' => $this->tradeName,
            'founded' => $this->founded,
            'head' => $this->head,
            'status_text' => $this->statusText,
            'status_date' => $this->statusDate,
            'nature_text' => $this->natureText,
            'equity' => $this->equity,
            'simples_optant' => $this->simplesOptant,
            'simei_optant' => $this->simeiOptant,
            'address' => $this->address->toArray(),
            'main_activity' => $this->mainActivity?->toArray(),
            'side_activities' => array_map(fn(CnpjActivityVO $a) => $a->toArray(), $this->sideActivities),
            'state_tax_id' => $mainRegistration?->number,
            'registrations' => array_map(fn(CnpjRegistrationVO $r) => $r->toArray(), $this->registrations),
            'phone' => $this->phone,
            'email' => $this->email,
        ];
    }

    /**
     * Verifica se a empresa está com situação Ativa.
     */
    public function isActive(): bool
    {
        return mb_strtolower($this->statusText ?? '') === 'ativa';
    }

    /**
     * Retorna a Inscrição Estadual principal (primeiro registro habilitado).
     */
    public function getMainStateRegistration(): ?CnpjRegistrationVO
    {
        foreach ($this->registrations as $registration) {
            if ($registration->enabled) {
                return $registration;
            }
        }

        return null;
    }

    /**
     * Retorna o CNPJ formatado (XX.XXX.XXX/XXXX-XX).
     */
    public function formattedTaxId(): string
    {
        $cnpj = preg_replace('/\D/', '', $this->taxId);

        return sprintf(
            '%s.%s.%s/%s-%s',
            substr($cnpj, 0, 2),
            substr($cnpj, 2, 3),
            substr($cnpj, 5, 3),
            substr($cnpj, 8, 4),
            substr($cnpj, 12, 2),
        );
    }

    private static function extractPhone(array $phones): ?string
    {
        if (empty($phones)) {
            return null;
        }

        $first = $phones[0];

        return "({$first['area']}) {$first['number']}";
    }

    private static function extractEmail(array $emails): ?string
    {
        if (empty($emails)) {
            return null;
        }

        return $emails[0]['address'] ?? null;
    }
}
