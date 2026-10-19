<?php

namespace App\Services\Commitments;

use App\Enum\Commitment\ProcessingStatus;
use App\Models\CommitmentImportFile;
use App\Services\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ReprocessCommitmentImportFileService
{
    public function execute(int $fileId, int $companyId, int $customerId, int $userId): void
    {
        app(CommitmentImportAccess::class)->authorize($companyId, $customerId, $userId);
        $file = DB::transaction(function () use ($fileId, $companyId, $customerId, $userId): CommitmentImportFile {
            $file = CommitmentImportFile::forCustomer($companyId, $customerId)->lockForUpdate()->findOrFail($fileId);
            if (! $file->canReprocess()) {
                throw ValidationException::withMessages(['file' => 'Arquivo em processamento ou com conciliações já revisadas/vinculadas. Não é possível sobrescrever seus dados.']);
            }
            $file->update(['status' => ProcessingStatus::QUEUED, 'processing_token' => null, 'processing_started_at' => null, 'last_error' => null]);
            app(AuditRecorder::class)->recordModelEvent($file, 'commitment.file_requeued', 'Reprocessamento de PDF solicitado.', actorUserId: $userId);

            return $file;
        });
        try {
            app(QueueCommitmentImportFile::class)->dispatch($file, $userId);
        } catch (Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['file' => 'Não foi possível enviar o arquivo para a fila. Tente novamente.']);
        } finally {
            app(RefreshCommitmentImportStatus::class)->execute($file->commitment_import_id);
        }
    }
}
