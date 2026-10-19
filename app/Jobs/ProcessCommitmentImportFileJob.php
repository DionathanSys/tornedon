<?php

namespace App\Jobs;

use App\Services\Commitments\ProcessCommitmentImportFileService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

class ProcessCommitmentImportFileJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 90;

    public bool $failOnTimeout = true;

    public string $runToken;

    public function __construct(public int $fileId, public int $companyId, public int $customerId, public int $userId)
    {
        $this->runToken = (string) Str::uuid();
    }

    public function handle(ProcessCommitmentImportFileService $service): void
    {
        $service->process($this->fileId, $this->companyId, $this->customerId, $this->userId, $this->runToken);
    }

    public function failed(?Throwable $exception): void
    {
        app(ProcessCommitmentImportFileService::class)->fail($this->fileId, $this->companyId, $this->customerId, $this->runToken, 'O processamento foi interrompido. Consulte o log e reprocesse o arquivo.', $this->userId);
    }
}
