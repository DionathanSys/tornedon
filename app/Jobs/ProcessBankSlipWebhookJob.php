<?php

namespace App\Jobs;

use App\Models\BankingEvent;
use App\Models\BankSlipEvent;
use App\Services\AccountReceivable\AccountReceivableService;
use App\Services\Financial\Banking\BankingEventService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ProcessBankSlipWebhookJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public int $uniqueFor = 900;

    public function __construct(private readonly int $eventId)
    {
        $this->queue = 'banking';
    }

    public function uniqueId(): string
    {
        return (string) $this->eventId;
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(AccountReceivableService $receivableService): void
    {
        $event = BankSlipEvent::find($this->eventId);
        if (! $event || $event->processed_at) {
            return;
        }
        if (! $event->bankSlip) {
            BankingEvent::firstOrCreate([
                'bank_account_connection_id' => $event->bank_account_connection_id,
                'event_key' => hash('sha256', 'unmatched-boleto:'.$event->id),
            ], [
                'company_id' => $event->company_id, 'source' => 'webhook', 'operation' => $event->event_type ?: 'unknown',
                'status' => 'needs_review', 'payload' => ['response' => $event->payload, 'legacy_event_id' => $event->id],
                'received_at' => $event->received_at, 'error' => 'Evento sem cobrança local correspondente.',
            ]);
            $event->update(['failed_at' => now(), 'processed_at' => now(), 'error' => 'Evento recebido sem boleto local correspondente.']);

            return;
        }
        $service = app(BankingEventService::class);
        $observation = $service->record($event->bankSlip, [
            'response' => [...$event->payload, 'valor' => $event->amount, 'status' => ['codigo' => $event->provider_status_code]],
            'successful' => $event->provider_status_code !== '9', 'charge_status' => $event->provider_status_code,
            'legacy_event_id' => $event->id,
        ], (string) ($event->event_type ?: 'ATUALIZACAO'), 'webhook');
        $service->process($observation);
        $observation->refresh();
        $event->update([
            'processed_at' => now(), 'failed_at' => $observation->status === 'needs_review' ? now() : null,
            'error' => $observation->error,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        BankSlipEvent::whereKey($this->eventId)->update(['failed_at' => now(), 'error' => $exception->getMessage()]);
    }
}
