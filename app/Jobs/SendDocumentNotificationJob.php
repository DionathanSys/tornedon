<?php

namespace App\Jobs;

use App\Enum\Email\EmailDispatchStatus;
use App\Models\EmailDispatch;
use App\Services\Email\DocumentNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendDocumentNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 60;

    public function __construct(
        private readonly int $emailDispatchId,
    ) {
        $this->queue = 'emails';
    }

    /**
     * @return int[]
     */
    public function backoff(): array
    {
        return [30, 120, 600, 1800, 7200];
    }

    public function handle(DocumentNotificationService $service): void
    {
        $dispatch = EmailDispatch::query()->find($this->emailDispatchId);
        if (! $dispatch) {
            return;
        }

        $service->processDispatch($dispatch, $this->attempts());
    }

    public function failed(\Throwable $e): void
    {
        $dispatch = EmailDispatch::query()->find($this->emailDispatchId);
        if (! $dispatch) {
            return;
        }

        $dispatch->update([
            'status' => EmailDispatchStatus::DEAD_LETTER,
            'attempts' => max((int) $dispatch->attempts, $this->attempts()),
            'error_message' => $e->getMessage(),
            'last_error_at' => now(),
        ]);

        Log::error('SendDocumentNotificationJob: dispatch movido para dead-letter', [
            'email_dispatch_id' => $dispatch->id,
            'document_type' => $dispatch->document_type?->value,
            'document_id' => $dispatch->document_id,
            'event' => $dispatch->event?->value,
            'attempts' => $this->attempts(),
            'exception' => $e->getMessage(),
        ]);
    }
}

