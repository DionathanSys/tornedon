<?php

namespace App\Services\Commitments;

use App\Enum\Commitment\ProcessingStatus;
use App\Models\CommitmentImport;
use Illuminate\Support\Facades\DB;

class RefreshCommitmentImportStatus
{
    public function execute(int $importId): void
    {
        DB::transaction(function () use ($importId): void {
            $import = CommitmentImport::query()->lockForUpdate()->findOrFail($importId);
            $statuses = $import->files()->pluck('status')->map(fn ($status) => $status instanceof ProcessingStatus ? $status->value : $status)->all();
            $rejected = collect($import->summary ?? [])->contains(fn ($entry) => $entry['status'] === 'rejected');
            $status = match (true) {
                in_array('processing', $statuses, true) => ProcessingStatus::PROCESSING,
                in_array('queued', $statuses, true) => ProcessingStatus::QUEUED,
                in_array('failed', $statuses, true), $rejected => ProcessingStatus::FAILED,
                in_array('needs_review', $statuses, true) => ProcessingStatus::NEEDS_REVIEW,
                default => ProcessingStatus::PROCESSED,
            };
            $import->update(['status' => $status]);
        });
    }
}
