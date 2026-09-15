<?php

namespace App\Services\Fiscal\Sefaz;

use App\Models\Company;
use App\Services\Fiscal\Sefaz\DTO\DfeDistributionDocument;

final class SefazDfeDocumentClassifier
{
    public function __construct(private readonly SefazDistributionDocumentParser $parser) {}

    /**
     * @param  array<string, mixed>|null  $parsed
     */
    public function isTakenBy(
        Company $company,
        DfeDistributionDocument $document,
        ?array $parsed = null,
    ): bool {
        $parsed ??= $this->parser->parse($document);
        $companyDocument = $this->normalizeDocument($company->document_number);

        if ($companyDocument === null) {
            return false;
        }

        $issuerDocument = $this->normalizeDocument($parsed['issuer_document'] ?? null);

        if ($issuerDocument === null || $issuerDocument === $companyDocument) {
            return false;
        }

        if (($parsed['is_full_xml'] ?? false) === true) {
            return $this->normalizeDocument($parsed['recipient_document'] ?? null) === $companyDocument;
        }

        return ($parsed['is_summary_xml'] ?? false) === true;
    }

    private function normalizeDocument(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return strlen($digits) === 14 ? $digits : null;
    }
}
