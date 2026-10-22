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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class UpdateBankSlipJob implements ShouldBeUnique, ShouldQueue
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
            if (! $charge || $charge->status->value !== 'update_pending' || ! $charge->pending_update || $charge->connection?->status !== 'active') {
                return;
            }
            if ($charge->operation_status !== 'ready') {
                return;
            }
            $reserved = DB::transaction(function () use ($charge): bool {
                $locked = BankSlip::query()->lockForUpdate()->findOrFail($charge->id);
                if ($locked->status->value !== 'update_pending' || $locked->operation_status !== 'ready') {
                    return false;
                }
                $locked->update(['operation_status' => 'sending', 'operation_attempt_id' => $locked->operation_attempt_id ?? (string) Str::uuid()]);

                return true;
            });
            if (! $reserved) {
                return;
            }
            $result = $registry->resolve($charge->connection)->update($charge);
            app(BankingEventService::class)->observe($charge, $result, 'update', 'request');
            if ($result->retryable) {
                throw new \RuntimeException($result->message ?: 'Resultado de alteração incerto.');
            }
        });
    }
}
