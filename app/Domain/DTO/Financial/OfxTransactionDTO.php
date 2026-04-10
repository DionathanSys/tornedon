<?php

namespace App\Domain\DTO\Financial;

use App\Enum\Financial\CashMovementDirection;

final readonly class OfxTransactionDTO
{
    public function __construct(
        public string $transactionDate,
        public float $amount,
        public float $signedAmount,
        public CashMovementDirection $direction,
        public string $description,
        public ?string $externalId = null,
        public ?string $documentNumber = null,
        public ?string $transactionType = null,
        public array $raw = [],
    ) {}

    public function with(
        ?string $transactionDate = null,
        ?float $amount = null,
        ?float $signedAmount = null,
        ?CashMovementDirection $direction = null,
        ?string $description = null,
        ?string $externalId = null,
        ?string $documentNumber = null,
        ?string $transactionType = null,
        ?array $raw = null,
    ): self {
        return new self(
            $transactionDate ?? $this->transactionDate,
            $amount ?? $this->amount,
            $signedAmount ?? $this->signedAmount,
            $direction ?? $this->direction,
            $description ?? $this->description,
            $externalId ?? $this->externalId,
            $documentNumber ?? $this->documentNumber,
            $transactionType ?? $this->transactionType,
            $raw ?? $this->raw,
        );
    }

    public function lineHash(): string
    {
        return hash('sha256', json_encode([
            'transaction_date' => $this->transactionDate,
            'signed_amount' => round($this->signedAmount, 4),
            'description' => $this->description,
            'external_id' => $this->externalId,
            'document_number' => $this->documentNumber,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function toArray(): array
    {
        return [
            'transaction_date' => $this->transactionDate,
            'amount' => $this->amount,
            'signed_amount' => $this->signedAmount,
            'direction' => $this->direction->value,
            'description' => $this->description,
            'external_id' => $this->externalId,
            'document_number' => $this->documentNumber,
            'transaction_type' => $this->transactionType,
            'line_hash' => $this->lineHash(),
            'raw' => $this->raw,
        ];
    }
}
