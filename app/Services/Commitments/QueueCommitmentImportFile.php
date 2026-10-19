<?php

namespace App\Services\Commitments;

use App\Jobs\ProcessCommitmentImportFileJob;
use App\Models\CommitmentImportFile;
use Illuminate\Support\Facades\DB;
use Throwable;

class QueueCommitmentImportFile
{
    public function dispatch(CommitmentImportFile $file, int $userId): void
    {
        $job = new ProcessCommitmentImportFileJob($file->id, $file->company_id, $file->customer_id, $userId);
        $queued = DB::transaction(function () use ($file, $job): bool {
            $locked = CommitmentImportFile::forCustomer($file->company_id, $file->customer_id)->lockForUpdate()->findOrFail($file->id);
            if ($locked->status->value !== 'queued' || $locked->processing_token !== null) {
                return false;
            }
            $locked->update(['processing_token' => $job->runToken]);

            return true;
        });
        if (! $queued) {
            return;
        }
        try {
            dispatch($job)->afterCommit();
        } catch (Throwable $exception) {
            CommitmentImportFile::forCustomer($file->company_id, $file->customer_id)->whereKey($file->id)
                ->where('status', 'queued')->where('processing_token', $job->runToken)
                ->update(['status' => 'failed', 'processing_token' => null, 'last_error' => 'Não foi possível enviar o arquivo para a fila. Reprocesse a importação.']);
            throw $exception;
        }
    }
}
