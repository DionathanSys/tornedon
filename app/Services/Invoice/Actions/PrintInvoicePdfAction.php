<?php

namespace App\Services\Invoice\Actions;

use App\Models\Invoice;
use App\Traits\HandlesActionResponse;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;

class PrintInvoicePdfAction
{
    use HandlesActionResponse;

    public function execute(Invoice $invoice): ?string
    {
        try {
            $invoice->loadMissing([
                'customer',
                'company',
                'createdBy',
                'requisitions.items.product',
                'serviceOrders.items.service',
                'productionOrders.items.product',
                'productionOrders.items.quoteItem',
            ]);

            $pdfBinary = Pdf::loadView('pdf.invoice', [
                'record' => $invoice,
            ])->setPaper('a4')->output();

            if ($pdfBinary === '') {
                $this->setError('Nao foi possivel gerar o PDF da fatura.');
                return null;
            }

            $this->setSuccess();

            return base64_encode($pdfBinary);
        } catch (\Exception $e) {
            $this->setError('Erro ao montar PDF da fatura: ' . $e->getMessage());

            Log::error('PrintInvoicePdfAction: excecao', [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'invoice_id' => $invoice->id,
                'exception'  => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
            ]);

            return null;
        }
    }
}
