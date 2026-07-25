<?php

namespace App\Services\FiscalDocument\Actions;

use App\Models\FiscalDocument;
use App\Models\FiscalDocumentPayload;
use Illuminate\Support\Facades\Log;

class UpsertFiscalDocumentPayloadAction
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(FiscalDocument $fiscalDocument, array $data): FiscalDocumentPayload
    {
        $payload = array_intersect_key($data, array_flip([
            'nfe_payload',
            'nfse_payload',
        ]));

        $values = [
            'company_id' => $fiscalDocument->company_id,
            ...$payload,
        ];

        Log::debug('Upserting fiscal document payload', [
            'fiscal_document_id' => $fiscalDocument->id,
            'company_id' => $fiscalDocument->company_id,
            'payload' => $payload,
        ]);
        
        return FiscalDocumentPayload::query()->updateOrCreate(
            ['fiscal_document_id' => $fiscalDocument->id],
            $values,
        );
    }
}
