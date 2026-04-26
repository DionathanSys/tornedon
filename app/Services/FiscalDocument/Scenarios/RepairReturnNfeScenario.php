<?php

namespace App\Services\FiscalDocument\Scenarios;

use App\Enum\FiscalDocument\OperationNature;
use App\Models\FiscalDocument;
use App\Services\FiscalDocument\Resolvers\FiscalDocumentReferenceResolver;

class RepairReturnNfeScenario extends SaleNfeScenario
{
    public function supports(FiscalDocument $document): bool
    {
        return $document->isNfe()
            && ($document->operation_nature?->value ?? $document->operation_nature) === OperationNature::RETORNO_CONSERTO->value;
    }

    public function code(): string
    {
        return 'repair_return';
    }

    public function validate(FiscalDocument $document, array &$errors): void
    {
        $reference = app(FiscalDocumentReferenceResolver::class)->resolvePrimaryReference($document);

        if ($reference === null) {
            $errors['tax_data.reference'][] = 'Retorno exige referência ao documento fiscal de origem.';
        }
    }
}
