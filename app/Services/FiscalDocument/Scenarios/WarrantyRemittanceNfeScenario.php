<?php

namespace App\Services\FiscalDocument\Scenarios;

use App\Enum\FiscalDocument\OperationNature;
use App\Models\FiscalDocument;
use App\Services\FiscalDocument\Resolvers\FiscalDocumentReferenceResolver;

class WarrantyRemittanceNfeScenario extends SaleNfeScenario
{
    public function supports(FiscalDocument $document): bool
    {
        return $document->isNfe()
            && ($document->operation_nature?->value ?? $document->operation_nature) === OperationNature::REMESSA_GARANTIA->value;
    }

    public function code(): string
    {
        return 'warranty_remittance';
    }

    public function resolveContext(FiscalDocument $document): \App\Domain\DTO\Fiscal\ScenarioContext
    {
        $reference = app(FiscalDocumentReferenceResolver::class)->resolvePrimaryReference($document);

        return new \App\Domain\DTO\Fiscal\ScenarioContext(
            referenceType: $reference?->referenceType,
            referenceDocumentKey: $reference?->documentKey,
            referenceFiscalDocumentId: $reference?->fiscalDocumentId,
            flags: ['no_reference_required' => true],
        );
    }
}
