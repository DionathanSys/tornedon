<?php

namespace App\Services\Financial\Banking;

use App\Jobs\ProcessBankSlipWebhookJob;
use App\Models\BankAccountConnection;
use App\Models\BankSlip;
use App\Models\BankSlipEvent;
use App\Services\Financial\Banking\DTO\BankSlipWebhookEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class BankSlipWebhookService
{
    public function __construct(
        private readonly BankSlipProviderRegistry $registry,
    ) {}

    public function ingest(BankAccountConnection $connection, array $payload, string $rawBody): ?BankSlipEvent
    {
        $provider = $this->registry->resolve($connection);

        if (! $provider->validateWebhook($connection, $payload, $rawBody)) {
            Log::warning('BankSlipWebhookService: assinatura invalida ou ausente', [
                'connection_id' => $connection->id,
            ]);

            return null;
        }

        $normalized = $provider->normalizeWebhook($payload);

        return DB::transaction(function () use ($connection, $normalized): BankSlipEvent {
            $event = BankSlipEvent::query()->firstOrCreate(
                [
                    'billing_provider_id' => $connection->billing_provider_id,
                    'provider_event_id' => $normalized->providerEventId,
                ],
                [
                    'company_id' => $connection->company_id,
                    'bank_account_connection_id' => $connection->id,
                    'billing_provider_id' => $connection->billing_provider_id,
                    'bank_slip_id' => $this->resolveBankSlipId($connection, $normalized),
                    'event_type' => $normalized->eventType,
                    'provider_status_code' => $normalized->statusCode,
                    'amount' => $normalized->amount,
                    'payload' => $normalized->payload,
                    'received_at' => now(),
                ],
            );

            if (! $event->processed_at) {
                ProcessBankSlipWebhookJob::dispatch($event->id)->afterCommit();
            }

            return $event;
        });
    }

    private function resolveBankSlipId(
        BankAccountConnection $connection,
        BankSlipWebhookEvent $event,
    ): ?int {
        if (! $event->identification) {
            return null;
        }

        return BankSlip::query()
            ->where('bank_account_connection_id', $connection->id)
            ->where('provider_identification', $event->identification)
            ->value('id');
    }
}
