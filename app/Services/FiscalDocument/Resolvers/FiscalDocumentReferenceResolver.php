<?php

namespace App\Services\FiscalDocument\Resolvers;

use App\Domain\DTO\Fiscal\FiscalDocumentReferenceData;
use App\Models\FiscalDocument;

class FiscalDocumentReferenceResolver
{
    public function resolvePrimaryReference(FiscalDocument $document): ?FiscalDocumentReferenceData
    {
        $purchaseReturnOrigin = data_get($document->tax_data, 'purchase_return_origin');

        if (is_array($purchaseReturnOrigin)) {
            return new FiscalDocumentReferenceData(
                referenceType: 'purchase_return_origin',
                fiscalDocumentId: $this->normalizeInt($purchaseReturnOrigin['fiscal_document_id'] ?? null),
                documentKey: $this->normalizeString($purchaseReturnOrigin['document_key'] ?? null),
                raw: $purchaseReturnOrigin,
            );
        }

        $genericReference = data_get($document->tax_data, 'reference');

        if (is_array($genericReference)) {
            return new FiscalDocumentReferenceData(
                referenceType: (string) ($genericReference['type'] ?? 'generic'),
                fiscalDocumentId: $this->normalizeInt($genericReference['fiscal_document_id'] ?? null),
                documentKey: $this->normalizeString($genericReference['document_key'] ?? null),
                raw: $genericReference,
            );
        }

        return null;
    }

    private function normalizeInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function normalizeString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
