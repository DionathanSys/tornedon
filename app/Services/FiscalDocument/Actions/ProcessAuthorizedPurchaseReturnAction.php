<?php

namespace App\Services\FiscalDocument\Actions;

use App\Models\FiscalDocument;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\Log;

class ProcessAuthorizedPurchaseReturnAction
{
    use HandlesActionResponse;

    public function __construct(
        private readonly ProcessPurchaseReturnStockAction $stockAction = new ProcessPurchaseReturnStockAction(),
        private readonly ProcessPurchaseReturnFinancialImpactAction $financialAction = new ProcessPurchaseReturnFinancialImpactAction(),
    ) {}

    /**
     * @return array{stock_movements:int,credits:int,replacement_payables:int,updated_payables:int,errors:string[],warnings:string[]}
     */
    public function execute(FiscalDocument $document, int $userId): array
    {
        $this->resetResponse();

        $result = [
            'stock_movements' => 0,
            'credits' => 0,
            'replacement_payables' => 0,
            'updated_payables' => 0,
            'errors' => [],
            'warnings' => [],
        ];

        if (! $document->isPurchaseReturn()) {
            $this->setSuccess();
            return $result;
        }

        $stockResult = $this->stockAction->execute($document, $userId);
        $result['stock_movements'] = $stockResult['stock_movements'];
        $result['errors'] = [...$result['errors'], ...$stockResult['errors']];

        if ($document->hasReturnFinancialConfiguration()) {
            $financialResult = $this->financialAction->execute($document->fresh(), $userId);

            if ($this->financialAction->hasError()) {
                $result['errors'][] = $this->financialAction->getMessage();
            } else {
                $result['credits'] = $financialResult['credits'];
                $result['replacement_payables'] = $financialResult['replacement_payables'];
                $result['updated_payables'] = $financialResult['updated_payables'];
                $result['warnings'] = [...$result['warnings'], ...$financialResult['warnings']];
            }
        }

        if ($result['errors'] !== []) {
            $this->setError('A devolução foi processada com erros.', ['errors' => $result['errors']]);
            return $result;
        }

        Log::info('Devolução processada com sucesso.', [
            'stock_movements' => $result['stock_movements'],
            'credits' => $result['credits'],
            'replacement_payables' => $result['replacement_payables'],
            'updated_payables' => $result['updated_payables'],
            'errors' => $result['errors'],
            'warnings' => $result['warnings'],
        ]);

        $this->setSuccess();

        return $result;
    }
}
