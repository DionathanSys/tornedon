<?php

namespace App\Jobs;

use App\Models\BankSlip;
use App\Services\Financial\Banking\BankingEventService;
use App\Services\Financial\Banking\BankSlipProviderRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

final class QueryBankSlipJob implements ShouldBeUnique, ShouldQueue
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
            if (! $charge || blank($charge->provider_identification) || $charge->connection?->status !== 'active') {
                return;
            }
            $provider = $registry->resolve($charge->connection);
            $service = app(BankingEventService::class);
            if ($charge->provider_operation_id && in_array($charge->status->value, ['cancel_pending', 'update_pending'], true)
                && $charge->operation_status !== 'confirmed') {
                $operation = $provider->queryEvent($charge, $charge->provider_operation_id);
                $service->observe($charge, $operation, 'event_query');
                if ($operation->retryable) {
                    throw new \RuntimeException($operation->message ?: 'Falha transitória ao consultar evento.');
                }
            }
            $result = $provider->query($charge->fresh());
            $service->observe($charge, $result, 'query');
            if ($result->retryable) {
                throw new \RuntimeException($result->message ?: 'Falha transitória ao consultar boleto.');
            }
        });
    }

    public function failed(\Throwable $exception): void
    {
        BankSlip::whereKey($this->bankSlipId)->update(['last_error' => $exception->getMessage()]);
    }
}
