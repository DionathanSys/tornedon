<?php

namespace App\Jobs;

use App\Enum\Financial\BankSlipStatus;
use App\Models\BankSlip;
use App\Services\Financial\Banking\BankSlipProviderRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class CancelBankSlipJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 900;

    public function __construct(private readonly int $bankSlipId)
    {
        $this->queue = 'banking';
    }

    public function uniqueId(): string
    {
        return (string) $this->bankSlipId;
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(BankSlipProviderRegistry $registry): void
    {
        $lock = Cache::lock('bank-slip-cancellation:'.$this->bankSlipId, 120);

        if (! $lock->get()) {
            return;
        }

        try {
            $bankSlip = BankSlip::query()
                ->with('connection.billingProvider')
                ->find($this->bankSlipId);

            if (! $bankSlip || $bankSlip->status !== BankSlipStatus::CANCEL_PENDING) {
                return;
            }

            $result = $registry->resolve($bankSlip->connection)->cancel($bankSlip);

            if ($result->successful) {
                $bankSlip->update([
                    'status' => BankSlipStatus::CANCELED->value,
                    'canceled_at' => now(),
                    'provider_status_code' => $result->statusCode,
                    'provider_status_message' => $result->statusMessage,
                    'last_synchronized_at' => now(),
                    'last_error' => null,
                    'provider_payload' => $result->payload,
                ]);

                return;
            }

            $message = $result->message ?: implode('; ', $result->errors);
            $bankSlip->update([
                'last_error' => $message,
                'last_synchronized_at' => now(),
                'provider_status_code' => $result->statusCode,
                'provider_status_message' => $result->statusMessage,
                'provider_payload' => $result->payload,
            ]);

            if ($result->retryable) {
                throw new RuntimeException($message ?: 'Falha transitoria ao cancelar boleto.');
            }
        } finally {
            $lock->release();
        }
    }

    public function failed(\Throwable $exception): void
    {
        BankSlip::query()
            ->whereKey($this->bankSlipId)
            ->update([
                'status' => BankSlipStatus::CANCEL_PENDING->value,
                'last_error' => $exception->getMessage(),
                'last_synchronized_at' => now(),
            ]);

        Log::error('CancelBankSlipJob: falha definitiva ao cancelar boleto', [
            'bank_slip_id' => $this->bankSlipId,
            'exception' => $exception->getMessage(),
        ]);
    }
}
