<?php

namespace App\Jobs;

use App\Enum\Financial\BankSlipStatus;
use App\Models\BankSlip;
use App\Models\CompanyPreference;
use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class ScheduleBankSlipCancellationJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 900;

    public function __construct(private readonly int $invoiceId)
    {
        $this->queue = 'banking';
    }

    public function uniqueId(): string
    {
        return (string) $this->invoiceId;
    }

    public function handle(): void
    {
        $invoice = Invoice::query()->find($this->invoiceId);

        if (! $invoice || ! CompanyPreference::shouldCancelBankSlipsWhenInvoiceCancelled($invoice->company_id)) {
            return;
        }

        $statuses = [
            BankSlipStatus::PENDING_REGISTRATION->value,
            BankSlipStatus::REGISTERED->value,
            BankSlipStatus::REGISTRATION_FAILED->value,
            BankSlipStatus::UPDATE_PENDING->value,
            BankSlipStatus::PARTIALLY_PAID->value,
            BankSlipStatus::NEEDS_REVIEW->value,
        ];

        $bankSlips = BankSlip::query()
            ->where('company_id', $invoice->company_id)
            ->whereHas('installment.accountReceivable', fn ($query) => $query->where('invoice_id', $invoice->id))
            ->whereIn('status', $statuses)
            ->get();

        foreach ($bankSlips as $bankSlip) {
            $bankSlip->update([
                'status' => BankSlipStatus::CANCEL_PENDING->value,
                'cancel_requested_at' => now(),
            ]);

            // This job is already dispatched after the invoice transaction commits.
            CancelBankSlipJob::dispatch($bankSlip->id);
        }

        Log::info('ScheduleBankSlipCancellationJob: cancelamentos de boletos programados', [
            'invoice_id' => $invoice->id,
            'bank_slips' => $bankSlips->count(),
        ]);
    }
}
