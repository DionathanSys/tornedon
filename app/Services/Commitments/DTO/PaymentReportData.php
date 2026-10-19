<?php

namespace App\Services\Commitments\DTO;

final readonly class PaymentReportData
{
    /** @param list<PaymentReportLineData> $lines */
    public function __construct(
        public string $issuerDocument,
        public string $creditorName,
        public string $creditorCode,
        public array $lines,
        public ?int $declaredTotalCents,
        public array $issues = [],
    ) {}

    public function extractedTotalCents(): int
    {
        return array_sum(array_map(fn (PaymentReportLineData $line): int => $line->amountCents, $this->lines));
    }

    public function requiresReview(): bool
    {
        if ($this->issues !== []) {
            return true;
        }
        foreach ($this->lines as $line) {
            if ($line->issues !== []) {
                return true;
            }
        }

        return false;
    }

    public function toArray(): array
    {
        return [
            'issuer_document' => $this->issuerDocument,
            'creditor_name' => $this->creditorName,
            'creditor_code' => $this->creditorCode,
            'line_count' => count($this->lines),
            'declared_total_cents' => $this->declaredTotalCents,
            'extracted_total_cents' => $this->extractedTotalCents(),
            'requires_review' => $this->requiresReview(),
            'issues' => $this->issues,
            'lines' => array_map(fn (PaymentReportLineData $line): array => $line->toArray(), $this->lines),
        ];
    }
}
