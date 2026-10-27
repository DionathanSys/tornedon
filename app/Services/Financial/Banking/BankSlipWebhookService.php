<?php

namespace App\Services\Financial\Banking;

use App\Jobs\ProcessBankingEventJob;
use App\Jobs\ProcessBankSlipWebhookJob;
use App\Models\BankAccountConnection;
use App\Models\BankingEvent;
use App\Models\BankSlip;
use App\Models\BankSlipEvent;
use App\Models\PixCharge;
use App\Services\Financial\Banking\DTO\BankSlipWebhookEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class BankSlipWebhookService
{
    public function __construct(
        private readonly BankSlipProviderRegistry $registry,
    ) {}

    public function ingest(BankAccountConnection $connection, array $payload, string $rawBody): BankSlipEvent|BankingEvent|null
    {
        $provider = $this->registry->resolve($connection);

        if (! $provider->validateWebhook($connection, $payload, $rawBody)) {
            Log::warning('BankSlipWebhookService: assinatura invalida ou ausente', [
                'connection_id' => $connection->id,
            ]);

            return null;
        }

        $normalized = $provider->normalizeWebhook($payload);

        $pix = PixCharge::withTrashed()->where('bank_account_connection_id', $connection->id)
            ->where('company_id', $connection->company_id)->where('provider_identification', $normalized->identification)->first();
        if ($pix && $this->resolveBankSlipId($connection, $normalized) === null) {
            return DB::transaction(function () use ($pix, $payload, $normalized) {
                $event = app(BankingEventService::class)->record($pix, [
                    'response' => $payload, 'successful' => $normalized->statusCode !== '9', 'charge_status' => $normalized->statusCode,
                ], $normalized->eventType ?: 'ATUALIZACAO', 'webhook');
                ProcessBankingEventJob::dispatch($event->id)->afterCommit();

                return $event;
            });
        }

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

    public function validateTest(BankAccountConnection $connection, array $payload, string $rawBody): bool
    {
        return $this->registry->resolve($connection)->validateWebhook($connection, $payload, $rawBody);
    }

    private function resolveBankSlipId(
        BankAccountConnection $connection,
        BankSlipWebhookEvent $event,
    ): ?int {
        if (! $event->identification) {
            return null;
        }

        return BankSlip::withTrashed()
            ->where('bank_account_connection_id', $connection->id)
            ->where('provider_identification', $event->identification)
            ->value('id');
    }
}
