<?php

namespace App\Jobs;

use App\Models\InvoiceOperation;
use App\Services\Invoice\ReverseServiceInvoiceWorkflow;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessInvoiceReversalJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 240;

    public int $uniqueFor = 300;

    public function __construct(public int $operationId) {}

    public function uniqueId(): string
    {
        return (string) $this->operationId;
    }

    public function handle(ReverseServiceInvoiceWorkflow $workflow): void
    {
        $operation = InvoiceOperation::find($this->operationId);
        if ($operation) {
            $workflow->process($operation);
        }
    }
}
