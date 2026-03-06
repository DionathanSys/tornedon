<?php

namespace App\Services\ServiceOrder\Actions;

use App\Enum\FiscalDocument\Status as FiscalDocumentStatus;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentItem;
use App\Services\Invoice\InvoiceService;
use App\Models\ServiceOrder;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\Log;

/**
 * Cria a cadeia Invoice → FiscalDocument → FiscalDocumentItems
 * a partir de uma Ordem de Serviço encerrada.
 *
 * Esta action NÃO muda o status da OS nem despacha o job de NF-e.
 * Isso é responsabilidade de InvoiceServiceOrderAction.
 */
class CreateInvoiceDocumentForServiceOrderAction
{
    use HandlesActionResponse;

    public function __construct(
        private int $userId,
    ) {}

    public function execute(ServiceOrder $order): ?FiscalDocument
    {
        try {
            // 1. Carrega itens com o serviço relacionado
            $items = $order->items()->with('service')->get();

            if ($items->isEmpty()) {
                $this->setError('Não é possível faturar uma OS sem itens.');
                return null;
            }

            // 2. Gera número da fatura (lockForUpdate interno ao service)
            $invoiceService = app(InvoiceService::class);
            $invoiceNumber  = $invoiceService->generateNumber($order->company_id);

            // 3. Calcula totais
            $totalItems   = $items->sum(fn ($i) => (float) $i->total_amount);
            $discount     = (float) ($order->discount_amount ?? 0);
            $totalInvoice = max(0, $totalItems - $discount);

            // 4. Cria a Invoice
            $invoice = $invoiceService->create([
                'customer_id'     => $order->customer_id,
                'company_id'      => $order->company_id,
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

            // 5. Vincula invoice_id na OS
            $order->update(['invoice_id' => $invoice->id]);

            // 6. Cria o FiscalDocument (NF-e pendente)
            $fiscalDocument = FiscalDocument::create([
                'customer_id'      => $order->customer_id,
                'company_id'       => $order->company_id,
                'invoice_id'       => $invoice->id,
                'status'           => FiscalDocumentStatus::PENDING,
                'issued_at'        => now()->toDateString(),
                'movement_at'      => now()->toDateString(),
                'operation_type'   => 1, // 1 = saída
                'operation_nature' => 'PRESTAÇÃO DE SERVIÇOS',
                'created_by'       => $this->userId,
            ]);

            // 7. Cria os FiscalDocumentItems (um por item da OS)
            foreach ($items as $index => $item) {
                $service = $item->service;

                FiscalDocumentItem::create([
                    'fiscal_document_id' => $fiscalDocument->id,
                    'service_id'         => $item->service_id,
                    'product_id'         => null,
                    'item_number'        => $index + 1,
                    'ncm_code'           => $service?->ncm_code,
                    'cfop_code'          => $service?->cfop_code,
                    'origin_code'        => $service?->origin_code ?? '07',
                    'unit_of_measure'    => $service?->unit_of_measure ?? 'UN',
                    'quantity'           => $item->quantity,
                    'unit_price'         => $item->unit_price,
                    'total_price'        => $item->total_amount,
                    'included_in_total'  => true,
                    'created_by'         => $this->userId,
                ]);
            }

            Log::info('CreateInvoiceDocumentForServiceOrderAction: Invoice + FiscalDocument criados', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'service_order_id'   => $order->id,
                'invoice_id'         => $invoice->id,
                'fiscal_document_id' => $fiscalDocument->id,
                'items_count'        => $items->count(),
            ]);

            $this->setSuccess();
            return $fiscalDocument;

        } catch (\Exception $e) {
            $this->setError('Erro ao criar cadeia de faturamento da OS: ' . $e->getMessage());

            Log::error('CreateInvoiceDocumentForServiceOrderAction: Erro inesperado', [
                'metodo'           => __METHOD__ . '@' . __LINE__,
                'service_order_id' => $order->id,
                'exception'        => $e->getMessage(),
                'trace'            => $e->getTraceAsString(),
            ]);

            return null;
        }
    }
}
