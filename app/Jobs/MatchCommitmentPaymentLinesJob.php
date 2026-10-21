<?php

namespace App\Jobs;

use App\Models\CommitmentPaymentLine;
use App\Services\Commitments\CommitmentImportAccess;
use App\Services\Commitments\MatchCommitmentPaymentsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class MatchCommitmentPaymentLinesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public function __construct(public int $companyId, public int $customerId, public int $fileId, public array $lineIds, public int $userId) {}

    public function handle(MatchCommitmentPaymentsService $service): void
    {
        app(CommitmentImportAccess::class)->authorize($this->companyId, $this->customerId, $this->userId);
        $ids = CommitmentPaymentLine::forCustomer($this->companyId, $this->customerId)->where('commitment_import_file_id', $this->fileId)
            ->whereIn('id', $this->lineIds)->orderBy('id')->pluck('id');
        foreach ($ids as $id) {
            $service->refresh($this->companyId, $id, $this->userId);
        }
    }
}
