<?php

namespace App\Services\Requisition\Actions;

use App\Models\Requisition;
use App\Services\Requisition\Support\RequisitionPdfDataFormatter;
use App\Traits\HandlesActionResponse;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;

class PrintRequisitionPdfAction
{
    use HandlesActionResponse;

    public function __construct(
        private readonly RequisitionPdfDataFormatter $dataFormatter,
    ) {}

    public function execute(Requisition $requisition): ?string
    {
        try {
            $requisition->loadMissing([
                'customer',
                'company',
                'salesperson',
                'serviceOrder',
                'equipment',
                'items.product',
            ]);

            $pdfData = $this->dataFormatter->format($requisition);

            $pdfBinary = Pdf::loadView('pdf.requisition', [
                'pdfData' => $pdfData,
            ])->setPaper('a4')->output();

            if ($pdfBinary === '') {
                $this->setError('Nao foi possivel gerar o PDF da requisicao.');
                return null;
            }

            $this->setSuccess();

            return base64_encode($pdfBinary);
        } catch (\Exception $e) {
            $this->setError('Erro ao montar PDF da requisicao: ' . $e->getMessage());

            Log::error('PrintRequisitionPdfAction: excecao', [
                'metodo'         => __METHOD__ . '@' . __LINE__,
                'requisition_id' => $requisition->id,
                'exception'      => $e->getMessage(),
                'trace'          => $e->getTraceAsString(),
            ]);

            return null;
        }
    }
}
