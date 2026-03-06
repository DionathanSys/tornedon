<?php

namespace App\Services\ProductionOrder\Actions;

use App\Enum\FiscalDocument\Status as FiscalDocumentStatus;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentItem;
use App\Models\ProductionOrder;
use App\Services\Invoice\InvoiceService;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\Log;

/**
 * Cria a cadeia Invoice → FiscalDocument → FiscalDocumentItems
 * a partir de uma Ordem de Produção concluída.
 *
 * Os itens da OP são mapeados via quoteItem.unit_price para obter o valor de venda.
 * Se não houver quoteItem (OP manual), o unit_price será 0 e deverá ser revisado.
 */
class CreateInvoiceDocumentForProductionOrderAction
{
    use HandlesActionResponse;

    public function __construct(
        private int $userId,
    ) {}

    public function execute(ProductionOrder $productionOrder): ?FiscalDocument
    {
        try {
            // 1. Carrega itens com produto e quoteItem associado
            $items = $productionOrder->items()->with('product', 'quoteItem')->get();

            if ($items->isEmpty()) {
                $this->setError('Não é possível faturar uma OP sem itens.');
                return null;
            }

            // 2. Gera número da fatura (lockForUpdate interno ao service)
            $invoiceService = app(InvoiceService::class);
            $invoiceNumber  = $invoiceService->generateNumber($productionOrder->company_id);

            // 3. Calcula totais via quoteItem.unit_price * quantity_approved
            $totalInvoice = $items->sum(function ($item) {
                $unitPrice = (float) ($item->quoteItem?->unit_price ?? 0);
                $qty       = (float) ($item->quantity_approved ?: $item->quantity_produced ?: $item->quantity);
                return $unitPrice * $qty;
            });

            // 4. Cria a Invoice
            $invoice = $invoiceService->create([
                'customer_id'     => $productionOrder->customer_id,
                'company_id'      => $productionOrder->company_id,
                'invoice_number'  => $invoiceNumber,
                'invoice_date'    => now()->toDateString(),
                'total_amount'    => $totalInvoice,
                'discount_amount' => 0,
            ], $this->userId);

            if ($invoiceService->hasError() || ! $invoice) {
                $this->setError(
                    'Falha ao criar fatura: ' . $invoiceService->getMessage(),
                    $invoiceService->getErrors(),
                );
                return null;
            }

            // 5. Vincula invoice_id na OP
            $productionOrder->update(['invoice_id' => $invoice->id]);

            // 6. Cria o FiscalDocument (NF-e pendente)
            $fiscalDocument = FiscalDocument::create([
                'customer_id'      => $productionOrder->customer_id,
                'company_id'       => $productionOrder->company_id,
                'invoice_id'       => $invoice->id,
                'status'           => FiscalDocumentStatus::PENDING,
                'issued_at'        => now()->toDateString(),
                'movement_at'      => now()->toDateString(),
                'operation_type'   => 1, // 1 = saída
                'operation_nature' => 'VENDA DE PRODUTOS FABRICADOS',
                'created_by'       => $this->userId,
            ]);

            // 7. Cria os FiscalDocumentItems (um por item da OP)
            foreach ($items as $index => $item) {
                $product   = $item->product;
                $unitPrice = (float) ($item->quoteItem?->unit_price ?? 0);
                $qty       = (float) ($item->quantity_approved ?: $item->quantity_produced ?: $item->quantity);

                FiscalDocumentItem::create([
                    'fiscal_document_id' => $fiscalDocument->id,
                    'product_id'         => $item->product_id,
                    'service_id'         => null,
                    'item_number'        => $index + 1,
                    'ncm_code'           => $product?->ncm_code,
                    'cfop_code'          => $product?->cfop_code,
                    'origin_code'        => $product?->origin_code ?? '3', // 3 = fabricação própria
                    'unit_of_measure'    => $item->unit_of_measure ?? 'UN',
                    'quantity'           => $qty,
                    'unit_price'         => $unitPrice,
                    'total_price'        => $unitPrice * $qty,
                    'included_in_total'  => true,
                    'created_by'         => $this->userId,
                ]);
            }

            Log::info('CreateInvoiceDocumentForProductionOrderAction: Invoice + FiscalDocument criados', [
                'metodo'              => __METHOD__ . '@' . __LINE__,
                'production_order_id' => $productionOrder->id,
                'invoice_id'          => $invoice->id,
                'fiscal_document_id'  => $fiscalDocument->id,
                'items_count'         => $items->count(),
            ]);

            $this->setSuccess();
            return $fiscalDocument;

        } catch (\Exception $e) {
            $this->setError('Erro ao criar cadeia de faturamento da OP: ' . $e->getMessage());

            Log::error('CreateInvoiceDocumentForProductionOrderAction: Erro inesperado', [
                'metodo'              => __METHOD__ . '@' . __LINE__,
                'production_order_id' => $productionOrder->id,
                'exception'           => $e->getMessage(),
                'trace'               => $e->getTraceAsString(),
            ]);

            return null;
        }
    }
}
