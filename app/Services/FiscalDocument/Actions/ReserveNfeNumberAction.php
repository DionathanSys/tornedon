<?php

namespace App\Services\FiscalDocument\Actions;

use App\Enum\FiscalDocument\NfeStatus;
use App\Models\FiscalDocument;
use App\Models\NfeSequence;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\Log;

/**
 * Reserva atomicamente o próximo número da NF-e para o documento fiscal.
 *
 * Deve ser chamada ANTES de montar o payload e enviar à API, garantindo que
 * o número está reservado mesmo em caso de falha no envio (permitindo reenvio
 * com o mesmo número, conforme orientação da IntegraNotas).
 */
class ReserveNfeNumberAction
{
    use HandlesActionResponse;

    public function execute(FiscalDocument $fiscalDocument, string $serie): bool
    {
        try {
            $result = NfeSequence::nextNumber(
                $fiscalDocument->company_id,
                $serie
            );

            $fiscalDocument->update([
                'document_number'  => (string) $result['number'],
                'document_series'  => $serie,
                'nfe_sequence_id'  => $result['sequence_id'],
            ]);

            Log::info('ReserveNfeNumberAction: número reservado', [
                'fiscal_document_id' => $fiscalDocument->id,
                'number'             => $result['number'],
                'serie'              => $serie,
                'sequence_id'        => $result['sequence_id'],
            ]);

            $this->setSuccess();
            return true;

        } catch (\Exception $e) {
            $this->setError('Erro ao reservar número da NF-e: ' . $e->getMessage());

            Log::error('ReserveNfeNumberAction: erro ao reservar número', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'fiscal_document_id' => $fiscalDocument->id,
                'exception'          => $e->getMessage(),
                'trace'              => $e->getTraceAsString(),
            ]);

            return false;
        }
    }
}
