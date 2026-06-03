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
        $this->resetResponse();

        $number = (int) preg_replace('/\D/', '', (string) ($fiscalDocument->rps_number ?? ''));
        $serie = trim((string) ($fiscalDocument->rps_series ?? ''));

        if ($number < 1 || $serie === '') {
            $this->setError('Documento não possui RPS reservado para conciliação.');

            return false;
        }

        $analysis = $this->analyze($fiscalDocument, $number, $serie);
        $released = false;

        $documentClearedForNewRps = false;

        if ($releaseDocumentNumber && $analysis['can_release_document_number']) {
            $released = NfseSequence::releaseLastNumberIfAvailable(
                (int) $fiscalDocument->company_id,
                $serie,
                $number,
                (int) $fiscalDocument->id,
            );

            if ($released) {
                $analysis = $this->analyze($fiscalDocument->fresh(), $number, $serie);
            }
        } elseif ($releaseDocumentNumber) {
            $documentClearedForNewRps = true;
        }

        $errors = $fiscalDocument->errors_messages ?? [];
        $errors[] = [
            'at' => now()->toDateTimeString(),
            'codigo' => 'rps_reconciliation',
            'mensagem' => $reason,
            'contexto' => [
                'rps_number' => $number,
                'rps_series' => $serie,
                'sequence_last_number' => $analysis['sequence_last_number'],
                'highest_used_number' => $analysis['highest_used_number'],
                'highest_other_number' => $analysis['highest_other_number'],
                'document_is_sequence_tail' => $analysis['document_is_sequence_tail'],
                'gap_justification_required' => $analysis['gap_justification_required'],
                'can_release_document_number' => $analysis['can_release_document_number'],
                'release_document_number' => $releaseDocumentNumber,
                'released_document_number' => $released,
                'document_cleared_for_new_rps' => $documentClearedForNewRps,
            ],
        ];

        $updates = [
            'status' => Status::PENDING->value,
            'errors_messages' => $errors,
        ];

        if ($released || $documentClearedForNewRps) {
            $updates['rps_number'] = null;
            $updates['rps_series'] = null;
            $updates['nfse_sequence_id'] = null;
            $updates['nfse_status'] = NfeStatus::PENDING->value;
            $this->setSuccess();
        } else {
            $updates['nfse_status'] = NfeStatus::RPS_RECONCILIATION_PENDING->value;
            $this->setSuccess();
        }

        $fiscalDocument->update($updates);

        return true;
    }

    /**
     * @return array{
     *     sequence_last_number:int,
     *     highest_used_number:int,
     *     highest_other_number:int,
     *     document_is_sequence_tail:bool,
     *     gap_justification_required:bool,
     *     can_release_document_number:bool
     * }
     */
    private function analyze(FiscalDocument $fiscalDocument, int $number, string $serie): array
    {
        $companyId = (int) $fiscalDocument->company_id;
        $sequenceLastNumber = (int) (NfseSequence::query()
            ->where('company_id', $companyId)
            ->where('serie', $serie)
            ->value('last_number') ?? 0);

        $otherNumbers = FiscalDocument::query()
            ->where('company_id', $companyId)
            ->where('document_type', 'nfse')
            ->where('rps_series', $serie)
            ->whereNotNull('rps_number')
            ->whereKeyNot($fiscalDocument->id)
            ->pluck('rps_number')
            ->map(fn ($usedNumber) => (int) preg_replace('/\D/', '', (string) $usedNumber))
            ->filter(fn (int $usedNumber) => $usedNumber > 0);

        $highestOtherNumber = (int) ($otherNumbers->max() ?? 0);
        $highestUsedNumber = max($number, $highestOtherNumber);
        $documentIsSequenceTail = $sequenceLastNumber === $number && $highestOtherNumber < $number;
        $gapJustificationRequired = $highestOtherNumber > $number || $sequenceLastNumber > $number;

        return [
            'sequence_last_number' => $sequenceLastNumber,
            'highest_used_number' => $highestUsedNumber,
            'highest_other_number' => $highestOtherNumber,
            'document_is_sequence_tail' => $documentIsSequenceTail,
            'gap_justification_required' => $gapJustificationRequired,
            'can_release_document_number' => $documentIsSequenceTail,
        ];
    }
}
