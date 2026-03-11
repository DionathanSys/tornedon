<?php

namespace App\Services\FiscalDocument\Actions;

use App\Models\FiscalDocument;
use App\Models\NfseSequence;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\Log;

/**
 * Reserva atomicamente o próximo número de RPS para a NFS-e.
 *
 * Deve ser chamada ANTES de montar o payload e enviar à API, garantindo que
 * o número está reservado mesmo em caso de falha no envio (permitindo reenvio
 * com o mesmo número).
 */
class ReserveRpsNumberAction
{
    use HandlesActionResponse;

    public function execute(FiscalDocument $fiscalDocument, string $serie): bool
    {
        try {
            $result = NfseSequence::nextNumber(
                $fiscalDocument->company_id,
                $serie,
            );

            $fiscalDocument->update([
                'rps_number'       => (string) $result['number'],
                'rps_series'        => $serie,
                'nfse_sequence_id' => $result['sequence_id'],
            ]);

            Log::info('ReserveRpsNumberAction: número RPS reservado', [
                'fiscal_document_id' => $fiscalDocument->id,
                'rps_number'         => $result['number'],
                'serie'              => $serie,
                'sequence_id'        => $result['sequence_id'],
            ]);

            $this->setSuccess();
            return true;

        } catch (\Exception $e) {
            $this->setError('Erro ao reservar número do RPS: ' . $e->getMessage());

            Log::error('ReserveRpsNumberAction: erro ao reservar número', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'fiscal_document_id' => $fiscalDocument->id,
                'exception'          => $e->getMessage(),
                'trace'              => $e->getTraceAsString(),
            ]);

            return false;
        }
    }
}
