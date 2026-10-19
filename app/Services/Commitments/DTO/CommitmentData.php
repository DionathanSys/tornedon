<?php

namespace App\Services\Commitments\DTO;

final readonly class CommitmentData
{
    public function __construct(
        public string $number,
        public int $fiscalYear,
        public string $issuedAt,
        public ?string $patrimony,
        public string $object,
        public int $amountCents,
        public int $retainedAmountCents,
        public int $netAmountCents,
        public string $issuerDocument,
        public string $creditorDocument,
        public string $creditorName,
        public string $originalNumber,
        public array $items,
        public array $issues = [],
        public int $page = 1,
    ) {}

    public function toArray(): array
    {
        return [
            'number' => $this->number,
            'fiscal_year' => $this->fiscalYear,
            'issued_at' => $this->issuedAt,
            'patrimony' => $this->patrimony,
            'object' => $this->object,
            'amount_cents' => $this->amountCents,
            'retained_amount_cents' => $this->retainedAmountCents,
            'net_amount_cents' => $this->netAmountCents,
            'issuer_document' => $this->issuerDocument,
            'creditor_document' => $this->creditorDocument,
            'creditor_name' => $this->creditorName,
            'original_number' => $this->originalNumber,
            'items' => $this->items,
            'issues' => $this->issues,
            'page' => $this->page,
        ];
    }
}
