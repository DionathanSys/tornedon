<?php

namespace App\Services\FiscalDocument\Actions;

use App\Models\FiscalDocument;
use App\Services\FiscalDocument\SaleNfeStockService;
use App\Traits\HandlesActionResponse;

class ProcessAuthorizedNfeStockMovementsAction
{
    use HandlesActionResponse;

    public function execute(FiscalDocument $fiscalDocument, ?int $actorId = null): bool
    {
        $service = app(SaleNfeStockService::class);
        $result = $service->apply($fiscalDocument, $actorId);
        if (! $result) {
            $this->setError($service->getMessage(), $service->getErrors());
        } else {
            $this->setSuccess();
        }

        return $result;
    }
}
