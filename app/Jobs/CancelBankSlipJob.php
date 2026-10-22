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

final class CancelBankSlipJob implements ShouldBeUnique, ShouldQueue
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
            if (! $charge || $charge->status->value !== 'cancel_pending' || $charge->connection?->status !== 'active') {
                return;
            }
            if ($charge->operation_kind === 'cancel' && (in_array($charge->operation_status, ['sending', 'pending', 'uncertain'], true) || $charge->provider_operation_id)) {
                return;
            }
            $reserved = DB::transaction(function () use ($charge): bool {
                $locked = BankSlip::query()->lockForUpdate()->findOrFail($charge->id);
                if ($locked->status->value !== 'cancel_pending') {
                    return false;
                }
                $locked->update(['operation_status' => 'sending', 'operation_kind' => 'cancel', 'operation_attempt_id' => (string) Str::uuid(), 'provider_operation_id' => null]);
                $intent = app(BankingEventService::class)->record($locked, ['response' => [], 'successful' => true, 'response_code' => '4'], 'cancel', 'intent');
                $intent->update(['status' => 'processed', 'processed_at' => now()]);

                return true;
            });
            if (! $reserved) {
                return;
            }
            $result = $registry->resolve($charge->connection)->cancel($charge);
            app(BankingEventService::class)->observe($charge, $result, 'cancel', 'request');
            if ($result->retryable) {
                throw new \RuntimeException($result->message ?: 'Resultado de cancelamento incerto.');
            }
        });
    }

    public function failed(\Throwable $exception): void
    {
        BankSlip::whereKey($this->bankSlipId)->update(['last_error' => $exception->getMessage()]);
    }
}
