<?php

namespace App\Domain\DTO\Cnpj;

class CnpjActivityVO
{
    public function __construct(
        public readonly int $id,
        public readonly string $text,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            text: $data['text'],
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'text' => $this->text,
        ];
    }

    /**
     * Retorna o código CNAE formatado (ex: 11.13-5/02).
     */
    public function formattedCode(): string
    {
        $code = str_pad((string) $this->id, 7, '0', STR_PAD_LEFT);

        return sprintf(
            '%s.%s-%s/%s',
            substr($code, 0, 2),
            substr($code, 2, 2),
            substr($code, 4, 1),
            substr($code, 5, 2),
        );
    }
}
