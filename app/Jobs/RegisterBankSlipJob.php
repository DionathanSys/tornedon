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

final class RegisterBankSlipJob implements ShouldBeUnique, ShouldQueue
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
        $lock = Cache::lock('bank-slip-registration:'.$this->bankSlipId, 120);

        if (! $lock->get()) {
            return;
        }

        try {
            $bankSlip = BankSlip::query()
                ->with(['connection.billingProvider', 'connection.bank', 'installment.accountReceivable.customer'])
                ->find($this->bankSlipId);

            if (! $bankSlip || ! in_array($bankSlip->status, [
                BankSlipStatus::PENDING_REGISTRATION,
                BankSlipStatus::REGISTRATION_FAILED,
                BankSlipStatus::UPDATE_PENDING,
            ], true)) {
                return;
            }

            $result = $registry->resolve($bankSlip->connection)->register($bankSlip);

            if ($result->successful) {
                $bankSlip->update([
                    'status' => BankSlipStatus::REGISTERED->value,
                    'provider_identification' => $result->providerIdentification ?? $bankSlip->provider_identification,
                    'provider_charge_id' => $result->providerChargeId,
                    'pdf_url' => $result->pdfUrl,
                    'digitable_line' => $result->digitableLine,
                    'barcode' => $result->barcode,
                    'provider_status_code' => $result->statusCode,
                    'provider_status_message' => $result->statusMessage,
                    'registered_at' => now(),
                    'last_synchronized_at' => now(),
                    'last_error' => null,
                    'provider_payload' => $result->payload,
                ]);

                $bankSlip->connection->update([
                    'last_success_at' => now(),
                    'last_error_at' => null,
                    'last_error' => null,
                ]);

                return;
            }

            $bankSlip->update([
                'status' => BankSlipStatus::REGISTRATION_FAILED->value,
                'provider_status_code' => $result->statusCode,
                'provider_status_message' => $result->statusMessage,
                'last_error' => $result->message ?: implode('; ', $result->errors),
                'last_synchronized_at' => now(),
                'provider_payload' => $result->payload,
            ]);

            $bankSlip->connection->update([
                'last_error_at' => now(),
                'last_error' => $result->message ?: implode('; ', $result->errors),
            ]);

            if ($result->retryable) {
                throw new \RuntimeException($result->message ?: 'Falha transitoria ao registrar boleto.');
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
                'status' => BankSlipStatus::REGISTRATION_FAILED->value,
                'last_error' => $exception->getMessage(),
                'last_synchronized_at' => now(),
            ]);

        Log::error('RegisterBankSlipJob: falha definitiva ao registrar boleto', [
            'bank_slip_id' => $this->bankSlipId,
            'exception' => $exception->getMessage(),
        ]);
    }
}
