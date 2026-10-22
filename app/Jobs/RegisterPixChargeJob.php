<?php

namespace App\Jobs;

use App\Models\PixCharge;
use App\Services\Financial\Banking\BankingEventService;
use App\Services\Financial\Banking\BankingOperationService;
use App\Services\Financial\Pix\PixProviderRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

final class RegisterPixChargeJob implements ShouldBeUnique, ShouldQueue
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
            $charge = PixCharge::find($this->pixChargeId);
            if (! $charge) {
                return;
            }
            $operations = app(BankingOperationService::class);
            if (! $operations->beginRegistration($charge)) {
                return;
            }
            $result = $registry->resolve($charge->connection)->register($charge->fresh());
            app(BankingEventService::class)->observe($charge, $result, 'register', 'request');
            if ($result->retryable) {
                throw new \RuntimeException($result->message ?: 'Resultado de emissão incerto; consulte antes de reenviar.');
            }
        });
    }

    public function failed(\Throwable $exception): void
    {
        PixCharge::whereKey($this->pixChargeId)->update(['last_error' => $exception->getMessage()]);
    }
}
