<?php

namespace App\Services\Commitments\DTO;

final readonly class PaymentReportLineData
{
    public function __construct(
        public int $position,
        public string $commitmentNumber,
        public int $fiscalYear,
        public string $paymentDate,
        public string $issuedAt,
        public int $amountCents,
        public ?string $patrimony,
        public string $object,
        public array $fiscalDocuments,
        public int $pageStart,
        public int $pageEnd,
        public string $originalText,
        public array $issues = [],
    ) {}

    public function toArray(): array
    {
        return [
            'position' => $this->position,
            'commitment_number' => $this->commitmentNumber,
            'fiscal_year' => $this->fiscalYear,
            'payment_date' => $this->paymentDate,
            'issued_at' => $this->issuedAt,
            'amount_cents' => $this->amountCents,
            'patrimony' => $this->patrimony,
            'object' => $this->object,
            'fiscal_documents' => $this->fiscalDocuments,
            'page_start' => $this->pageStart,
            'page_end' => $this->pageEnd,
            'original_text' => $this->originalText,
            'issues' => $this->issues,
        ];
    }
}
