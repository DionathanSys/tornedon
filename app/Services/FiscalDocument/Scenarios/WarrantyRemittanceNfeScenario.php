<?php

namespace App\Services\FiscalDocument\Scenarios;

use App\Enum\FiscalDocument\OperationNature;
use App\Models\FiscalDocument;

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
        return new \App\Domain\DTO\Fiscal\ScenarioContext(
            flags: ['no_reference_required' => true],
        );
    }
}
