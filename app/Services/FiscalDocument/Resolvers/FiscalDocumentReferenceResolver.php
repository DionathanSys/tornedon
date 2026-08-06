<?php

namespace App\Services\FiscalDocument\Resolvers;

use App\Domain\DTO\Fiscal\FiscalDocumentReferenceData;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentItemOrigin;

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

        return $this->resolvePurchaseReturnReferenceFromItemOrigins($document);
    }

    private function resolvePurchaseReturnReferenceFromItemOrigins(FiscalDocument $document): ?FiscalDocumentReferenceData
    {
        $origin = FiscalDocumentItemOrigin::query()
            ->with('originDocument:id,document_key,document_number,document_series,issued_at')
            ->where('return_fiscal_document_id', $document->id)
            ->orderBy('id')
            ->first();

        if (! $origin instanceof FiscalDocumentItemOrigin) {
            return null;
        }

        $documentKey = $this->normalizeString($origin->origin_document_key)
            ?? $this->normalizeString($origin->originDocument?->document_key);

        if ($documentKey === null) {
            return null;
        }

        $raw = [
            'fiscal_document_id' => $origin->origin_fiscal_document_id,
            'document_number' => $origin->originDocument?->document_number,
            'document_series' => $origin->originDocument?->document_series,
            'document_key' => $documentKey,
            'issued_at' => $origin->originDocument?->issued_at?->toDateString(),
            'source' => 'fiscal_document_item_origins',
        ];

        return new FiscalDocumentReferenceData(
            referenceType: 'purchase_return_origin',
            fiscalDocumentId: $this->normalizeInt($origin->origin_fiscal_document_id),
            documentKey: $documentKey,
            raw: $raw,
        );
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
