<?php

namespace App\Services\Fiscal\Actions;

use App\Domain\DTO\Fiscal\FiscalContextDTO;
use App\Domain\DTO\Fiscal\FiscalDecisionDTO;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentItem;
use App\Services\Fiscal\FiscalDecisionService;
use App\Traits\HandlesActionResponse;

class ResolveFiscalContextAction
{
    use HandlesActionResponse;

    public function __construct(
        private readonly FiscalDecisionService $decisionService,
    ) {
    }

    /**
     * Resolve a decisão fiscal para cada item de um documento fiscal.
     *
     * @return array<int, FiscalDecisionDTO> Indexado por item_number
     */
    public function execute(FiscalDocument $document, array $items): array
    {
        try {
            $document->loadMissing(['company', 'customer.address']);

            $decisions = [];

            foreach ($items as $item) {
                $fiscalItem = $item instanceof FiscalDocumentItem
                    ? $item
                    : $this->toFiscalDocumentItem($item);

                $context = FiscalContextDTO::fromFiscalDocumentItem($document, $fiscalItem);

                $decision = $this->decisionService->resolve($context);

                $itemNumber = $fiscalItem->item_number ?? $item['item_number'] ?? count($decisions) + 1;
                $decisions[$itemNumber] = $decision;
            }

            $this->setSuccess();

            return $decisions;
        } catch (\Exception $e) {
            $this->setError('Erro ao resolver contexto fiscal: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Converte um array de dados de item em FiscalDocumentItem para montagem do contexto.
     */
    private function toFiscalDocumentItem(array $data): FiscalDocumentItem
    {
        $item = new FiscalDocumentItem();
        $item->forceFill($data);

        return $item;
    }
}
