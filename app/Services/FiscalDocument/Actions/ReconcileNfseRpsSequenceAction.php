<?php

namespace App\Services\FiscalDocument\Actions;

use App\Enum\FiscalDocument\NfeStatus;
use App\Enum\FiscalDocument\Status;
use App\Models\FiscalDocument;
use App\Models\NfseSequence;
use App\Traits\HandlesActionResponse;

class ReconcileNfseRpsSequenceAction
{
    use HandlesActionResponse;

    public function execute(FiscalDocument $fiscalDocument, string $reason, bool $releaseDocumentNumber = false): bool
    {
        $number = (int) preg_replace('/\D/', '', (string) ($fiscalDocument->rps_number ?? ''));
        $serie = trim((string) ($fiscalDocument->rps_series ?? ''));

        if ($number < 1 || $serie === '') {
            $this->setError('Documento não possui RPS reservado para conciliação.');

            return false;
        }

        $currentLastNumber = NfseSequence::query()
            ->where('company_id', $fiscalDocument->company_id)
            ->where('serie', $serie)
            ->value('last_number');

        $highestUsedNumber = NfseSequence::highestUsedNumber((int) $fiscalDocument->company_id, $serie);

        $errors = $fiscalDocument->errors_messages ?? [];
        $errors[] = [
            'at' => now()->toDateTimeString(),
            'codigo' => 'rps_reconciliation',
            'mensagem' => $reason,
            'contexto' => [
                'rps_number' => $number,
                'rps_series' => $serie,
                'sequence_last_number' => (int) ($currentLastNumber ?? 0),
                'highest_used_number' => $highestUsedNumber,
                'release_document_number' => $releaseDocumentNumber,
            ],
        ];

        $updates = [
            'status' => Status::PENDING->value,
            'errors_messages' => $errors,
        ];

        if ($releaseDocumentNumber) {
            $updates['rps_number'] = null;
            $updates['rps_series'] = null;
            $updates['nfse_sequence_id'] = null;
            $updates['nfse_status'] = NfeStatus::PENDING->value;
        } else {
            $updates['nfse_status'] = NfeStatus::RPS_RECONCILIATION_PENDING->value;
        }

        $fiscalDocument->update($updates);

        $this->setSuccess();

        return true;
    }
}
