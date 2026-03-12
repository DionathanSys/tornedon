<?php

namespace App\Services\FiscalDocument\Validators\Items;

use App\Enum\FiscalDocument\DocumentModel;
use App\Models\FiscalDocument;

class FiscalDocumentItemValidatorResolver
{
    public static function validateCreate(array $data): array
    {
        $documentType = self::resolveDocumentType($data['fiscal_document_id'] ?? null);

        return match ($documentType) {
            DocumentModel::NFSE->value => NfseItemValidator::validateCreate($data),
            default => NfeItemValidator::validateCreate($data),
        };
    }

    public static function validateCreateMany(array $data): array
    {
        $items = $data['items'] ?? [];
        $firstFiscalDocumentId = $items[0]['fiscal_document_id'] ?? null;
        $documentType = self::resolveDocumentType($firstFiscalDocumentId);

        return match ($documentType) {
            DocumentModel::NFSE->value => NfseItemValidator::validateCreateMany($data),
            default => NfeItemValidator::validateCreateMany($data),
        };
    }

    public static function validateUpdate(array $data, ?int $fiscalDocumentId = null): array
    {
        $documentType = self::resolveDocumentType($fiscalDocumentId ?? ($data['fiscal_document_id'] ?? null));

        return match ($documentType) {
            DocumentModel::NFSE->value => NfseItemValidator::validateUpdate($data),
            default => NfeItemValidator::validateUpdate($data),
        };
    }

    private static function resolveDocumentType(mixed $fiscalDocumentId): ?string
    {
        if (empty($fiscalDocumentId)) {
            return null;
        }

        return FiscalDocument::query()
            ->whereKey($fiscalDocumentId)
            ->value('document_type');
    }
}
