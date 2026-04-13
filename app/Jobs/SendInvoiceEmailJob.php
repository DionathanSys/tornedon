<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Services\Invoice\Actions\SendInvoiceEmailAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendInvoiceEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 180;

    public function __construct(
        private readonly int $invoiceId,
        private readonly string $subject,
        private readonly string $body,
        private readonly int $userId,
    ) {
        $this->queue = 'emails';
    }

    public function handle(SendInvoiceEmailAction $action): void
    {
        $invoice = Invoice::query()->find($this->invoiceId);

        if (! $invoice) {
            Log::error('SendInvoiceEmailJob: fatura não encontrada', [
                'invoice_id' => $this->invoiceId,
                'user_id' => $this->userId,
            ]);

            return;
        }

        $ok = $action->execute(
            invoice: $invoice,
            subject: $this->subject,
            body: $this->body,
            userId: $this->userId,
        );

        if (! $ok || $action->hasError()) {
            throw new \RuntimeException($action->getMessage() ?: 'Falha ao enviar e-mail da fatura.');
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('SendInvoiceEmailJob: falha definitiva no envio', [
            'invoice_id' => $this->invoiceId,
            'user_id' => $this->userId,
            'exception' => $e->getMessage(),
        ]);
    }
}
