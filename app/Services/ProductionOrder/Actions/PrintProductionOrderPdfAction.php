<?php

namespace App\Services\ProductionOrder\Actions;

use App\Models\ProductionOrder;
use App\Traits\HandlesActionResponse;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;

class PrintProductionOrderPdfAction
{
    use HandlesActionResponse;

    public function execute(ProductionOrder $productionOrder): ?string
    {
        try {
            $productionOrder->loadMissing([
                'customer',
                'company',
                'assignedOperator',
                'requisition',
                'items.product',
                'items.quoteItem',
            ]);

            $pdfBinary = Pdf::loadView('pdf.production-order', [
                'record' => $productionOrder,
            ])->setPaper('a4')->output();

            if ($pdfBinary === '') {
                $this->setError('Não foi possível gerar o PDF da ordem de produção.');
                return null;
            }

            $this->setSuccess();

            return base64_encode($pdfBinary);
        } catch (\Exception $e) {
            $this->setError('Erro ao montar PDF da ordem de produção: ' . $e->getMessage());

            Log::error('PrintProductionOrderPdfAction: excecao', [
                'metodo'              => __METHOD__ . '@' . __LINE__,
                'production_order_id' => $productionOrder->id,
                'exception'           => $e->getMessage(),
                'trace'               => $e->getTraceAsString(),
            ]);

            return null;
        }
    }
}
