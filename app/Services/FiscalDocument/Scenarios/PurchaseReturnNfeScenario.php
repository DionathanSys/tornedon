<?php

namespace App\Services\FiscalDocument\Scenarios;

use App\Enum\FiscalDocument\OperationNature;
use App\Models\FiscalDocument;
use App\Services\FiscalDocument\Resolvers\FiscalDocumentReferenceResolver;

class PurchaseReturnNfeScenario extends SaleNfeScenario
{
    public function supports(FiscalDocument $document): bool
    {
        return $document->isNfe()
            && ($document->operation_nature?->value ?? $document->operation_nature) === OperationNature::DEVOLUCAO_COMPRA->value;
    }

    public function code(): string
    {
        return 'purchase_return';
    }

    public function validate(FiscalDocument $document, array &$errors): void
    {
        $reference = app(FiscalDocumentReferenceResolver::class)->resolvePrimaryReference($document);

        if ($reference?->documentKey === null) {
            $errors['tax_data.purchase_return_origin.document_key'][] = 'Nota de devolução exige chave da NF-e de origem.';
        }
    }
}
