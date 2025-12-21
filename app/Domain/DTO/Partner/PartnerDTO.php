<?php

namespace App\Domain\DTO\Partner;

class PartnerDTO
{
    public function __construct(
        public readonly string $name,
        public readonly string|array $type,
        public readonly string $documentType,
        public readonly string $documentNumber,
        public readonly bool $isActive,
        public readonly string $stateTaxId,
        public readonly string $stateTaxIdicator,
        public readonly string $municipalTaxIdicator,
        public readonly string $email,
        public readonly string $phone,
        public readonly ?array $address,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            type: $data['type'],
            documentType: $data['document_type'],
            documentNumber: $data['document_number'],
            isActive: $data['is_active'],
            stateTaxId: $data['state_tax_id'],
            stateTaxIdicator: $data['state_tax_idicator'],
            municipalTaxIdicator: $data['municipal_tax_idicator'],
            email: $data['email'],
            phone: $data['phone'],
            address: $data['address'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'document_type' => $this->documentType,
            'document_number' => $this->documentNumber,
            'is_active' => $this->isActive,
            'state_tax_id' => $this->stateTaxId,
            'state_tax_idicator' => $this->stateTaxIdicator,
            'municipal_tax_idicator' => $this->municipalTaxIdicator,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
        ];
    }
}