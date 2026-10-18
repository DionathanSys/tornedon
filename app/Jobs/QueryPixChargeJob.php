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

final class QueryPixChargeJob implements ShouldBeUnique, ShouldQueue
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
            if (! $charge || blank($charge->provider_identification) || $charge->connection?->status !== 'active'
                || in_array($charge->status->value, ['paid', 'payment_returned', 'canceled'], true)) {
                return;
            }
            $result = $registry->resolve($charge->connection)->query($charge);
            app(BankingEventService::class)->observe($charge, $result, 'query');
            if ($result->retryable) {
                throw new \RuntimeException($result->message ?: 'Falha transitória ao consultar PIX.');
            }
        });
    }

    public function failed(\Throwable $exception): void
    {
        PixCharge::whereKey($this->pixChargeId)->update(['last_error' => $exception->getMessage()]);
    }
}
