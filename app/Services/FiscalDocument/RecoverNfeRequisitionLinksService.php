<?php

namespace App\Services\FiscalDocument;

use App\Enum\FiscalDocument\Status;
use App\Enum\StockMovement\Type;
use App\Models\FiscalDocument;
use App\Models\Invoice;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\StockMovement;
use App\Services\Access\RoleRecordScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class RecoverNfeRequisitionLinksService
{
    /** Diagnose first; apply all missing links only when the entire document is unambiguous. */
    public function execute(int $documentId, int $companyId, bool $apply = false): array
    {
        return DB::transaction(function () use ($documentId, $companyId, $apply): array {
            $document = FiscalDocument::where('company_id', $companyId)->findOrFail($documentId);
            // Same lock order as fiscal stock preparation/authorization.
            $invoice = Invoice::where('company_id', $companyId)->lockForUpdate()->findOrFail($document->invoice_id);
            $document = FiscalDocument::where('company_id', $companyId)->lockForUpdate()->findOrFail($documentId);
            if ($document->invoice_id !== $invoice->id
                || ! app(SaleNfeStockService::class)->applies($document)
                || $document->canceled || $document->status === Status::CANCELLED
                || $document->isNfeAuthorized() || $document->isNfeCanceled()
                || $document->isNfeQueued() || $document->isNfeInProcessing()
                || $invoice->canceled || $invoice->operations()->where('active_slot', 1)->exists()
                || $document->stockEffects()->whereNotNull('processed_at')->exists()) {
                throw ValidationException::withMessages(['document' => 'A recuperação exige NF-e de venda vinculada à fatura, sem emissão em andamento, cancelamento ou estoque fiscal processado.']);
            }

            $items = $document->items()->orderBy('id')->lockForUpdate()->get();
            $requisitions = Requisition::where('company_id', $companyId)->where('invoice_id', $invoice->id)
                ->orderBy('id')->lockForUpdate()->get();
            $sources = RequisitionItem::whereIn('requisition_id', $requisitions->modelKeys())
                ->orderBy('id')->lockForUpdate()->get();
            $used = $items->pluck('requisition_item_id')->filter();
            $rows = [];
            $errors = $items->isEmpty() ? ['A NF-e não possui itens.'] : [];
            $proposals = [];

            foreach ($items as $item) {
                $candidates = $sources->filter(fn ($source) => (int) $source->product_id === (int) $item->product_id
                    && (string) $source->unit_of_measure === (string) $item->unit_of_measure
                    && abs((float) $source->quantity - (float) $item->quantity) < 0.00001);
                $message = 'Vínculo existente';
                $source = $item->requisition_item_id ? $sources->firstWhere('id', $item->requisition_item_id) : null;
                if ($item->requisition_item_id) {
                    if (! $source || ! $candidates->contains('id', $source->id) || $used->filter(fn ($id) => (int) $id === (int) $source->id)->count() !== 1) {
                        $message = 'Vínculo existente incompatível ou duplicado; conferir manualmente.';
                        $errors[] = "Item #{$item->id}: {$message}";
                    }
                } else {
                    $candidates = $candidates->reject(fn ($candidate) => $used->contains($candidate->id));
                    if ($candidates->count() !== 1) {
                        $message = $candidates->isEmpty() ? 'Nenhuma origem compatível disponível.' : 'Mais de uma origem compatível; conferir manualmente.';
                        $errors[] = "Item #{$item->id}: {$message}";
                    } else {
                        $source = $candidates->sole();
                        $proposals[$item->id] = $source->id;
                        $message = 'Vínculo recuperável';
                    }
                }

                if ($source) {
                    $movements = StockMovement::withoutGlobalScope(RoleRecordScope::class)->where('company_id', $companyId)
                        ->where(function ($query) use ($source, $item): void {
                            $query->where(fn ($query) => $query->where('source_type', 'requisition_item')->where('source_id', $source->id))
                                ->orWhere(fn ($query) => $query->where('source_type', 'requisition')->where('source_id', $source->requisition_id)->where('product_id', $source->product_id))
                                ->orWhere(fn ($query) => $query->where('source_type', 'fiscal_document_item')->where('source_id', $item->id));
                        })
                        ->where('type', Type::EXIT->value)->orderBy('id')->lockForUpdate()->pluck('id');
                    $requisition = $requisitions->firstWhere('id', $source->requisition_id);
                    if ($source->stock_consumed || $source->stock_consumed_at || $requisition->stock_consumed || $movements->isNotEmpty()) {
                        $message .= '; baixa anterior: conferir estoque (movimentos: '.($movements->implode(', ') ?: 'sem movimento rastreado').')';
                        $errors[] = "Item #{$item->id}: existe consumo anterior na requisição #{$source->requisition_id}, item #{$source->id}. Regularização fiscal do estoque necessária antes da recuperação.";
                    }
                }
                $rows[] = [
                    'item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'quantity' => $item->quantity,
                    'unit' => $item->unit_of_measure,
                    'candidate_ids' => $candidates->pluck('id')->implode(', '),
                    'source_id' => $source?->id,
                    'message' => $message,
                ];
            }

            // Do not assign the same unique candidate to two otherwise identical fiscal lines.
            if (count($proposals) !== count(array_unique($proposals))) {
                $errors[] = 'Dois ou mais itens fiscais disputam a mesma origem. Conferir manualmente.';
            }

            $applied = false;
            if ($apply && $errors === [] && $proposals !== []) {
                foreach ($proposals as $itemId => $sourceId) {
                    $items->firstWhere('id', $itemId)->update(['requisition_item_id' => $sourceId]);
                }
                Log::info('Vínculos de requisição recuperados para NF-e antiga.', [
                    'company_id' => $companyId, 'fiscal_document_id' => $documentId,
                    'invoice_id' => $invoice->id, 'links' => $proposals,
                ]);
                $applied = true;
            }

            return ['rows' => $rows, 'errors' => $errors, 'missing' => count($proposals), 'applied' => $applied];
        });
    }
}
