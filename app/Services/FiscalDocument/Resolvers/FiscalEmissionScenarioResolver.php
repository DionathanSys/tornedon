<?php

namespace App\Services\FiscalDocument\Resolvers;

use App\Models\FiscalDocument;
use App\Services\FiscalDocument\Contracts\FiscalEmissionScenarioInterface;
use App\Services\FiscalDocument\Scenarios\MunicipalNfseScenario;
use App\Services\FiscalDocument\Scenarios\NationalNfseScenario;
use App\Services\FiscalDocument\Scenarios\PurchaseReturnNfeScenario;
use App\Services\FiscalDocument\Scenarios\RepairRemittanceNfeScenario;
use App\Services\FiscalDocument\Scenarios\RepairReturnNfeScenario;
use App\Services\FiscalDocument\Scenarios\SaleNfeScenario;

class FiscalEmissionScenarioResolver
{
    /**
     * @return array<int,FiscalEmissionScenarioInterface>
     */
    private function scenarios(): array
    {
        return [
            new PurchaseReturnNfeScenario(),
            new RepairReturnNfeScenario(),
            new RepairRemittanceNfeScenario(),
            new NationalNfseScenario(),
            new MunicipalNfseScenario(),
            new SaleNfeScenario(),
        ];
    }

    public function resolve(FiscalDocument $document): ?FiscalEmissionScenarioInterface
    {
        foreach ($this->scenarios() as $scenario) {
            if ($scenario->supports($document)) {
                return $scenario;
            }
        }

        return null;
    }
}
