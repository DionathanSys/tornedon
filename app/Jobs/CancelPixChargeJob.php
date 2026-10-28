<?php

namespace App\Jobs;

use App\Models\PixCharge;
use App\Services\Financial\Banking\BankingEventService;
use App\Services\Financial\Pix\PixProviderRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CancelPixChargeJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

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
        Cache::lock('banking-charge:pix:'.$this->pixChargeId, 330)->get(function () use ($registry): void {
            $charge = DB::transaction(function (): ?PixCharge {
                $charge = PixCharge::query()->lockForUpdate()->find($this->pixChargeId);
                if (! $charge || $charge->status->value !== 'cancel_pending' || $charge->connection?->status !== 'active'
                    || in_array($charge->operation_status, ['sending', 'pending', 'uncertain'], true)) {
                    return null;
                }
                $charge->update(['operation_kind' => 'cancel', 'operation_status' => 'sending', 'operation_attempt_id' => (string) Str::uuid()]);
                $intent = app(BankingEventService::class)->record($charge, ['response' => [], 'successful' => true, 'response_code' => '4'], 'cancel', 'intent');
                $intent->update(['status' => 'processed', 'processed_at' => now()]);

                return $charge;
            });
            if (! $charge) {
                return;
            }
            try {
                $result = $registry->resolve($charge->connection)->cancel($charge);
                app(BankingEventService::class)->observe($charge, $result, 'cancel', 'request');
            } catch (\Throwable $exception) {
                PixCharge::whereKey($charge->id)->where('operation_status', 'sending')->update([
                    'operation_status' => 'uncertain',
                    'last_error' => 'Não foi possível aplicar o retorno do cancelamento. Consulte o provedor. '.$exception->getMessage(),
                ]);

                throw $exception;
            }
            if ($result->retryable) {
                throw new \RuntimeException($result->message ?: 'Resultado do cancelamento PIX incerto. Consulte o provedor.');
            }
        });
    }

    public function failed(\Throwable $exception): void
    {
        PixCharge::whereKey($this->pixChargeId)->update(['last_error' => $exception->getMessage()]);
    }
}
