<?php

namespace App\Services\StockMovement\Actions;

use App\Models\Product;
use App\Services\StockMovement\Support\KardexPdfDataFormatter;
use App\Traits\HandlesActionResponse;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;

class PrintKardexPdfAction
{
    use HandlesActionResponse;

    public function __construct(
        private readonly KardexPdfDataFormatter $dataFormatter,
    ) {}

    /**
     * @param  array{start_date?: string|null, end_date?: string|null}  $filters
     */
    public function execute(Product $product, int $companyId, array $filters = []): ?string
    {
        try {
            $product->loadMissing(['company']);

            $pdfData = $this->dataFormatter->format($product, $companyId, $filters);

            $pdfBinary = Pdf::loadView('pdf.stock-kardex', [
                'pdfData' => $pdfData,
            ])->setPaper('a4', 'landscape')->output();

            if ($pdfBinary === '') {
                $this->setError('Nao foi possivel gerar o PDF do kardex.');

                return null;
            }

            $this->setSuccess();

            return base64_encode($pdfBinary);
        } catch (\Exception $e) {
            $this->setError('Erro ao montar PDF do kardex: ' . $e->getMessage());

            Log::error('PrintKardexPdfAction: excecao', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'product_id' => $product->id,
                'company_id' => $companyId,
                'filters' => $filters,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }
}
