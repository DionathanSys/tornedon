<?php

namespace App\Jobs;

use App\Services\Commitments\ProcessCommitmentBillingBatchService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessCommitmentBillingBatchJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public function __construct(public int $batchId, public int $companyId, public string $runToken) {}

    public function handle(ProcessCommitmentBillingBatchService $service): void
    {
        $service->process($this->batchId, $this->companyId, $this->runToken);
    }

    public function failed(?Throwable $exception): void
    {
        app(ProcessCommitmentBillingBatchService::class)->fail($this->batchId, $this->companyId, $this->runToken,
            'O processamento foi interrompido. Retome os empenhos; as etapas concluídas serão preservadas.');
    }
}
