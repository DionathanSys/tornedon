<?php

namespace App\Services\Requisition\Actions;

use App\Enum\FiscalDocument\Status as FiscalDocumentStatus;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentItem;
use App\Models\Requisition;
use App\Services\Invoice\InvoiceService;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\Log;

/**
 * Cria a cadeia Invoice → FiscalDocument → FiscalDocumentItems
 * a partir de uma Requisição encerrada.
 *
 * Esta action NÃO muda o status da Requisição nem despacha o job de NF-e.
 * Isso é responsabilidade de InvoiceRequisitionAction.
 */
class CreateInvoiceDocumentForRequisitionAction
{
    use HandlesActionResponse;

    public function __construct(
        private int $userId,
    ) {}

    public function execute(Requisition $requisition): ?FiscalDocument
    {
        try {
            // 1. Carrega itens com o produto relacionado
            $items = $requisition->items()->with('product')->get();

            if ($items->isEmpty()) {
                $this->setError('Não é possível faturar uma requisição sem itens.');
                return null;
            }

            // 2. Gera número da fatura (lockForUpdate interno ao service)
            $invoiceService = app(InvoiceService::class);
            $invoiceNumber  = $invoiceService->generateNumber($requisition->company_id);

            // 3. Calcula totais
            $totalItems   = $items->sum(fn ($i) => (float) $i->total_amount);
            $discount     = (float) ($requisition->discount_amount ?? 0);
            $totalInvoice = max(0, $totalItems - $discount);

            // 4. Cria a Invoice
            $invoice = $invoiceService->create([
                'customer_id'     => $requisition->customer_id,
                'company_id'      => $requisition->company_id,
                'invoice_number'  => $invoiceNumber,
                'invoice_date'    => now()->toDateString(),
                'total_amount'    => $totalInvoice,
                'discount_amount' => $discount,
            ], $this->userId);

            if ($invoiceService->hasError() || ! $invoice) {
                $this->setError(
                    'Falha ao criar fatura: ' . $invoiceService->getMessage(),
                    $invoiceService->getErrors(),
                );
                return null;
            }

            // 5. Vincula invoice_id na Requisição
            $requisition->update(['invoice_id' => $invoice->id]);

            // 6. Cria o FiscalDocument (NF-e pendente)
            $fiscalDocument = FiscalDocument::create([
                'customer_id'      => $requisition->customer_id,
                'company_id'       => $requisition->company_id,
                'invoice_id'       => $invoice->id,
                'status'           => FiscalDocumentStatus::PENDING,
                'issued_at'        => now()->toDateString(),
                'movement_at'      => now()->toDateString(),
                'operation_type'   => 1, // 1 = saída
                'operation_nature' => 'VENDA DE MERCADORIA',
                'created_by'       => $this->userId,
            ]);

            // 7. Cria os FiscalDocumentItems (um por item da Requisição)
            foreach ($items as $index => $item) {
                $product = $item->product;

                FiscalDocumentItem::create([
                    'fiscal_document_id' => $fiscalDocument->id,
                    'product_id'         => $item->product_id,
                    'service_id'         => null,
                    'item_number'        => $index + 1,
                    'ncm_code'           => $product?->ncm_code,
                    'cfop_code'          => $product?->cfop_code,
                    'origin_code'        => $product?->origin_code ?? '0',
                    'unit_of_measure'    => $item->unit_of_measure,
                    'quantity'           => $item->quantity,
                    'unit_price'         => $item->unit_price,
                    'total_price'        => $item->total_amount,
                    'included_in_total'  => true,
                    'created_by'         => $this->userId,
                ]);
            }

            Log::info('CreateInvoiceDocumentForRequisitionAction: Invoice + FiscalDocument criados', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'requisition_id'     => $requisition->id,
                'invoice_id'         => $invoice->id,
                'fiscal_document_id' => $fiscalDocument->id,
                'items_count'        => $items->count(),
            ]);

            $this->setSuccess();
            return $fiscalDocument;

        } catch (\Exception $e) {
            $this->setError('Erro ao criar cadeia de faturamento da requisição: ' . $e->getMessage());

            Log::error('CreateInvoiceDocumentForRequisitionAction: Erro inesperado', [
                'metodo'         => __METHOD__ . '@' . __LINE__,
                'requisition_id' => $requisition->id,
                'exception'      => $e->getMessage(),
                'trace'          => $e->getTraceAsString(),
            ]);

            return null;
        }
    }
}
