<?php

namespace App\Jobs;

use App\Enum\Financial\BankSlipStatus;
use App\Models\BankAccountConnection;
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

final class QueryBankSlipJob implements ShouldBeUnique, ShouldQueue
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
        $lock = Cache::lock('bank-slip-query:'.$this->bankSlipId, 120);

        if (! $lock->get()) {
            return;
        }

        try {
            $bankSlip = BankSlip::query()
                ->with(['connection.billingProvider', 'connection.bank'])
                ->find($this->bankSlipId);

            if (! $bankSlip || blank($bankSlip->provider_identification)) {
                return;
            }

            $connection = $bankSlip->connection;

            if (! $connection instanceof BankAccountConnection) {
                return;
            }

            $result = $registry->resolve($connection)->query($bankSlip);
            $statusCode = (string) ($result->statusCode ?? '');
            $updates = [
                'provider_identification' => $result->providerIdentification ?? $bankSlip->provider_identification,
                'provider_charge_id' => $result->providerChargeId ?: $bankSlip->provider_charge_id,
                'provider_status_code' => $result->statusCode,
                'provider_status_message' => $result->statusMessage,
                'pdf_url' => $result->pdfUrl ?: $bankSlip->pdf_url,
                'digitable_line' => $result->digitableLine ?: $bankSlip->digitable_line,
                'barcode' => $result->barcode ?: $bankSlip->barcode,
                'last_synchronized_at' => now(),
                'provider_payload' => $result->payload,
                'last_error' => $result->successful
                    ? null
                    : ($result->message ?: implode('; ', $result->errors)),
            ];

            if ($result->successful) {
                $updates['status'] = match ($statusCode) {
                    '2' => BankSlipStatus::REGISTERED->value,
                    '4' => BankSlipStatus::CANCELED->value,
                    '7' => BankSlipStatus::PAYMENT_RETURNED->value,
                    default => $bankSlip->status?->value ?? BankSlipStatus::NEEDS_REVIEW->value,
                };

                if ($statusCode === '2' && ! $bankSlip->registered_at) {
                    $updates['registered_at'] = now();
                }

                if ($statusCode === '4' && ! $bankSlip->canceled_at) {
                    $updates['canceled_at'] = now();
                }
            }

            $bankSlip->update($updates);

            if ($result->retryable) {
                throw new RuntimeException($result->message ?: 'Falha transitoria ao consultar boleto.');
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
                'last_error' => $exception->getMessage(),
                'last_synchronized_at' => now(),
            ]);

        Log::error('QueryBankSlipJob: falha definitiva ao consultar boleto', [
            'bank_slip_id' => $this->bankSlipId,
            'exception' => $exception->getMessage(),
        ]);
    }
}
