<?php

namespace App\Domain\DTO\Cnpj;

class CnpjAddressVO
{
    public function __construct(
        public readonly ?string $street,
        public readonly ?string $number,
        public readonly ?string $district,
        public readonly ?string $city,
        public readonly ?string $state,
        public readonly ?string $zip,
        public readonly ?string $details,
        public readonly ?int $municipalityCode,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            street: $data['street'] ?? null,
            number: $data['number'] ?? null,
            district: $data['district'] ?? null,
            city: $data['city'] ?? null,
            state: $data['state'] ?? null,
            zip: $data['zip'] ?? null,
            details: $data['details'] ?? null,
            municipalityCode: $data['municipality'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'street' => $this->street,
            'number' => $this->number,
            'district' => $this->district,
            'city' => $this->city,
            'state' => $this->state,
            'zip' => $this->zip,
            'details' => $this->details,
            'municipality_code' => $this->municipalityCode,
        ];
    }

    public function formatted(): string
    {
        $parts = array_filter([
            $this->street,
            $this->number,
            $this->details,
            $this->district,
            $this->city ? "{$this->city}/{$this->state}" : null,
            $this->zip ? "CEP: {$this->zip}" : null,
        ]);

        return implode(', ', $parts);
    }
}
