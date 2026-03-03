<?php

namespace App\Domain\DTO\Cnpj;

class CnpjRegistrationVO
{
    public function __construct(
        public readonly string $number,
        public readonly string $state,
        public readonly bool $enabled,
        public readonly ?string $statusText,
        public readonly ?string $typeText,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            number: $data['number'],
            state: $data['state'],
            enabled: $data['enabled'] ?? false,
            statusText: $data['status']['text'] ?? null,
            typeText: $data['type']['text'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'number' => $this->number,
            'state' => $this->state,
            'enabled' => $this->enabled,
            'status_text' => $this->statusText,
            'type_text' => $this->typeText,
        ];
    }
}
