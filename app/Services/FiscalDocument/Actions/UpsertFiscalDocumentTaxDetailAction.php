<?php

namespace App\Services\FiscalDocument\Actions;

use App\Models\FiscalDocument;
use App\Models\FiscalDocumentTaxDetail;

class UpsertFiscalDocumentTaxDetailAction
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(FiscalDocument $fiscalDocument, array $data): FiscalDocumentTaxDetail
    {
        $payload = array_intersect_key($data, array_flip([
            'freight_data',
            'payment_data',
            'tax_totals',
            'fiscal_metadata',
        ]));

        if (isset($data['tax_data']) && is_array($data['tax_data'])) {
            $payload += $this->splitTaxData($data['tax_data']);
        }

        $values = [
            'company_id' => $fiscalDocument->company_id,
            ...$payload,
        ];

        return FiscalDocumentTaxDetail::query()->updateOrCreate(
            ['fiscal_document_id' => $fiscalDocument->id],
            $values,
        );
    }

    /**
     * @param  array<string, mixed>  $taxData
     * @return array<string, mixed>
     */
    private function splitTaxData(array $taxData): array
    {
        $totals = $taxData['totais'] ?? null;
        unset($taxData['totais']);

        return array_filter([
            'tax_totals' => is_array($totals) ? $totals : null,
            'fiscal_metadata' => $taxData !== [] ? $taxData : null,
        ], fn (mixed $value): bool => $value !== null);
    }
}
