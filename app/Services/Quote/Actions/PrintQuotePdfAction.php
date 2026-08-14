<?php

namespace App\Services\Quote\Actions;

use App\Models\Quote;
use App\Services\Quote\Support\QuotePdfDataFormatter;
use App\Traits\HandlesActionResponse;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;

class PrintQuotePdfAction
{
    use HandlesActionResponse;

    public function __construct(
        private readonly QuotePdfDataFormatter $dataFormatter,
    ) {}

    public function execute(Quote $quote): ?string
    {
        try {
            $quote->loadMissing(['customer', 'company', 'items.product', 'items.service']);

            $pdfBinary = Pdf::loadView('pdf.quote', [
                'pdfData' => $this->dataFormatter->format($quote),
            ])->setPaper('a4')->output();

            if ($pdfBinary === '') {
                $this->setError('Não foi possível gerar o PDF do orçamento.');

                return null;
            }

            $this->setSuccess();

            return base64_encode($pdfBinary);
        } catch (\Exception $e) {
            $this->setError('Erro ao montar PDF do orçamento: '.$e->getMessage());

            Log::error('PrintQuotePdfAction: exceção', [
                'quote_id' => $quote->id,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }
}
