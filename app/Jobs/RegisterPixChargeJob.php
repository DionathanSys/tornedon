<?php

namespace App\Jobs;

use App\Enum\Financial\PixChargeStatus;
use App\Models\BankAccountConnection;
use App\Models\PixCharge;
use App\Services\Financial\Pix\PixProviderRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class RegisterPixChargeJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 900;

    public function __construct(private readonly int $pixChargeId)
    {
        $this->queue = 'banking';
    }

    public function uniqueId(): string
    {
        return (string) $this->pixChargeId;
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(PixProviderRegistry $registry): void
    {
        $lock = Cache::lock('pix-charge-registration:'.$this->pixChargeId, 120);

        if (! $lock->get()) {
            return;
        }

        try {
            $charge = PixCharge::query()
                ->with(['connection.billingProvider', 'connection.bank', 'installment.accountReceivable.customer'])
                ->find($this->pixChargeId);

            if (! $charge || ! in_array($charge->status, [
                PixChargeStatus::PENDING_REGISTRATION,
                PixChargeStatus::REGISTRATION_FAILED,
            ], true)) {
                return;
            }

            $connection = $charge->connection;

            if (! $connection instanceof BankAccountConnection) {
                return;
            }

            $result = $registry->resolve($connection)->register($charge);

            if ($result->successful) {
                $charge->update([
                    'status' => PixChargeStatus::REGISTERED,
                    'provider_identification' => $result->providerIdentification ?: $charge->provider_identification,
                    'provider_charge_id' => $result->providerChargeId,
                    'qr_code' => $result->qrCode,
                    'pix_copy_paste' => $result->pixCopyPaste,
                    'provider_status_code' => $result->statusCode,
                    'provider_status_message' => $result->statusMessage,
                    'registered_at' => now(),
                    'last_synchronized_at' => now(),
                    'last_error' => null,
                    'provider_payload' => $result->payload,
                ]);

                $connection->update([
                    'last_success_at' => now(),
                    'last_error_at' => null,
                    'last_error' => null,
                ]);

                return;
            }

            $message = $result->message ?: implode('; ', $result->errors);
            $charge->update([
                'status' => PixChargeStatus::REGISTRATION_FAILED,
                'provider_status_code' => $result->statusCode,
                'provider_status_message' => $result->statusMessage,
                'last_error' => $message,
                'last_synchronized_at' => now(),
                'provider_payload' => $result->payload,
            ]);

            $connection->update([
                'last_error_at' => now(),
                'last_error' => $message,
            ]);

            if ($result->retryable) {
                throw new RuntimeException($message ?: 'Falha transitória ao registrar cobrança PIX.');
            }
        } finally {
            $lock->release();
        }
    }

    public function failed(\Throwable $exception): void
    {
        PixCharge::query()
            ->whereKey($this->pixChargeId)
            ->update([
                'status' => PixChargeStatus::REGISTRATION_FAILED,
                'last_error' => $exception->getMessage(),
                'last_synchronized_at' => now(),
            ]);

        Log::error('RegisterPixChargeJob: falha definitiva ao registrar cobrança PIX', [
            'pix_charge_id' => $this->pixChargeId,
            'exception' => $exception->getMessage(),
        ]);
    }
}
