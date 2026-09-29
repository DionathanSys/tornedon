<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Services\Financial\Banking\BankSlipIssuanceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class ScheduleBankSlipIssuanceJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 600;

    public function __construct(private readonly int $invoiceId)
    {
        $this->queue = 'banking';
    }

    public function uniqueId(): string
    {
        return (string) $this->invoiceId;
    }

    public function handle(BankSlipIssuanceService $service): void
    {
        $invoice = Invoice::query()->find($this->invoiceId);

        if (! $invoice) {
            Log::warning('ScheduleBankSlipIssuanceJob: fatura nao encontrada', [
                'invoice_id' => $this->invoiceId,
            ]);

            return;
        }

        $scheduled = $service->scheduleForInvoice($invoice);

        Log::info('ScheduleBankSlipIssuanceJob: emissao de boletos programada', [
            'invoice_id' => $invoice->id,
            'scheduled' => $scheduled,
        ]);
    }
}
