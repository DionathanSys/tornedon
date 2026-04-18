<?php

namespace App\Services\ServiceOrder\Actions;

use App\Models\ServiceOrder;
use App\Services\ServiceOrder\Support\ServiceOrderPdfDataFormatter;
use App\Traits\HandlesActionResponse;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;

class PrintServiceOrderPdfAction
{
    use HandlesActionResponse;

    public function __construct(
        private readonly ServiceOrderPdfDataFormatter $dataFormatter,
    ) {}

    public function execute(ServiceOrder $serviceOrder): ?string
    {
        try {
            $serviceOrder->loadMissing([
                'customer',
                'company',
                'equipment.owner',
                'technician',
                'supervisor',
                'salesperson',
                'items.service',
                'requisition.items.product',
            ]);

            $pdfData = $this->dataFormatter->format($serviceOrder);

            $pdfBinary = Pdf::loadView('pdf.service-order', [
                'record' => $serviceOrder,
                'pdfData' => $pdfData,
            ])->setPaper('a4')->output();

            if ($pdfBinary === '') {
                $this->setError('Não foi possível gerar o PDF da ordem de serviço.');
                return null;
            }

            $this->setSuccess();

            return base64_encode($pdfBinary);
        } catch (\Exception $e) {
            $this->setError('Erro ao montar PDF da ordem de serviço: ' . $e->getMessage());
@
            Log::error('PrintServiceOrderPdfAction: excecao', [
                'metodo'           => __METHOD__ . '@' . __LINE__,
                'service_order_id' => $serviceOrder->id,
                'exception'        => $e->getMessage(),
                'trace'            => $e->getTraceAsString(),
            ]);

            return null;
        }
    }
}
