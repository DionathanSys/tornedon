<?php

namespace App\Jobs;

use App\Models\BankSlip;
use App\Services\Financial\Banking\BankingEventService;
use App\Services\Financial\Banking\BankingOperationService;
use App\Services\Financial\Banking\BankSlipProviderRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

final class RegisterBankSlipJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

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
        Cache::lock('banking-charge:boleto:'.$this->bankSlipId, 330)->get(function () use ($registry): void {
            $charge = BankSlip::find($this->bankSlipId);
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
                throw new \RuntimeException($result->message ?: 'Resultado de emissão incerto; consulte pela action antes de reenviar.');
            }
        });
    }

    public function failed(\Throwable $exception): void
    {
        BankSlip::whereKey($this->bankSlipId)->update(['last_error' => $exception->getMessage()]);
    }
}
