<?php

namespace App\Services\FiscalDocument\Actions;

use App\Models\FiscalDocument;
use App\Models\NfeInvalidationRequest;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\Log;

class CreateNfeInvalidationRequestAction
{
    use HandlesActionResponse;

    public function execute(FiscalDocument $fiscalDocument, string $serie, int $number, int $userId, int $replacementNumber): ?NfeInvalidationRequest
    {
        $request = NfeInvalidationRequest::query()->firstOrCreate(
            [
                'company_id' => $fiscalDocument->company_id,
                'serie' => $serie,
                'number_start' => $number,
                'number_end' => $number,
            ],
            [
                'fiscal_document_id' => $fiscalDocument->id,
                'justification' => sprintf(
                    'Inutilização do número %d da série %s após rejeição e renumeração da NF-e para %d.',
                    $number,
                    $serie,
                    $replacementNumber,
                ),
                'status' => 'pending',
                'requested_by' => $userId,
            ]
        );

        Log::info('Solicitação de inutilização registrada.', [
            'fiscal_document_id' => $fiscalDocument->id,
            'serie' => $serie,
            'number_start' => $number,
            'number_end' => $number,
            'company_id' => $fiscalDocument->company_id,
            'user_id' => $userId,
            'replacement_number' => $replacementNumber,
        ]);

        $this->setSuccess();

        return $request;
    }
}
