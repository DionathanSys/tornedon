<?php

namespace App\Services\FiscalDocumentItem\Actions;

use App\Models\FiscalDocumentItem;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\Log;

class ReorderFiscalDocumentItemsAction
{
    use HandlesActionResponse;

    public function execute(int $fiscalDocumentId): bool
    {
        try {
            $items = FiscalDocumentItem::query()
                ->where('fiscal_document_id', $fiscalDocumentId)
                ->orderBy('item_number')
                ->orderBy('created_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'item_number']);

            foreach ($items->values() as $index => $item) {
                $nextItemNumber = $index + 1;

                if ((int) $item->item_number === $nextItemNumber) {
                    continue;
                }

                FiscalDocumentItem::query()
                    ->whereKey($item->id)
                    ->update(['item_number' => $nextItemNumber]);
            }

            Log::info('Itens do documento fiscal reordenados com sucesso', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'fiscal_document_id' => $fiscalDocumentId,
                'total_items' => $items->count(),
            ]);

            $this->setSuccess();

            return true;
        } catch (\Exception $e) {
            $this->setError('Erro ao reordenar itens do documento fiscal');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'fiscal_document_id' => $fiscalDocumentId,
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }
}
