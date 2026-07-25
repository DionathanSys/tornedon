<?php

namespace App\Services\FiscalDocument\Actions;

use App\Models\FiscalDocument;
use App\Models\FiscalDocumentTaxDetail;
use Illuminate\Support\Facades\Log;

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
            'tax_data',
        ]));

        $values = [
            'company_id' => $fiscalDocument->company_id,
            ...$payload,
        ];

        Log::debug('Upserting fiscal document tax detail', [
            'fiscal_document_id' => $fiscalDocument->id,
            'company_id' => $fiscalDocument->company_id,
            'payload' => $payload,
        ]);
        
        return FiscalDocumentTaxDetail::query()->updateOrCreate(
            ['fiscal_document_id' => $fiscalDocument->id],
            $values,
        );
    }
}
