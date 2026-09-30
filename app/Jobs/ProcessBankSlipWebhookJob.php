<?php

namespace App\Jobs;

use App\Enum\Financial\BankSlipStatus;
use App\Models\BankSlip;
use App\Models\BankSlipEvent;
use App\Services\AccountReceivable\AccountReceivableService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class ProcessBankSlipWebhookJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

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
        $lock = Cache::lock('bank-slip-webhook:'.$this->eventId, 120);

        if (! $lock->get()) {
            return;
        }

        try {
            DB::transaction(function () use ($receivableService): void {
                $event = BankSlipEvent::query()
                    ->with(['bankSlip.installment.accountReceivable', 'bankSlip.connection'])
                    ->lockForUpdate()
                    ->find($this->eventId);

                if (! $event || $event->processed_at) {
                    return;
                }

                if (! $event->bankSlip) {
                    $this->markForReview($event, 'Evento recebido sem boleto local correspondente.');

                    return;
                }

                $bankSlip = $event->bankSlip;
                $statusCode = (string) ($event->provider_status_code ?? '');

                if ($statusCode === '3') {
                    $this->processPayment($event, $bankSlip, $receivableService);

                    return;
                }

                $bankSlip->update([
                    'status' => match ($statusCode) {
                        '2' => BankSlipStatus::REGISTERED->value,
                        '4' => BankSlipStatus::CANCELED->value,
                        '7' => BankSlipStatus::PAYMENT_RETURNED->value,
                        default => BankSlipStatus::NEEDS_REVIEW->value,
                    },
                    'provider_status_code' => $statusCode !== '' ? $statusCode : null,
                    'provider_status_message' => data_get($event->payload, 'status.mensagem')
                        ?? data_get($event->payload, 'status.message'),
                    'canceled_at' => $statusCode === '4' ? now() : $bankSlip->canceled_at,
                    'last_synchronized_at' => now(),
                    'provider_payload' => $event->payload,
                    ...$this->providerDocumentAttributes($event, $bankSlip),
                ]);

                $event->update(['processed_at' => now()]);
            });
        } finally {
            $lock->release();
        }
    }

    public function failed(\Throwable $exception): void
    {
        BankSlipEvent::query()
            ->whereKey($this->eventId)
            ->update([
                'failed_at' => now(),
                'error' => $exception->getMessage(),
            ]);

        Log::error('ProcessBankSlipWebhookJob: falha ao processar evento', [
            'event_id' => $this->eventId,
            'exception' => $exception->getMessage(),
        ]);
    }

    private function processPayment(
        BankSlipEvent $event,
        BankSlip $bankSlip,
        AccountReceivableService $receivableService,
    ): void {
        $amount = (float) $event->amount;
        $installment = $bankSlip->installment;

        if (! $installment || $amount <= 0) {
            $this->markForReview($event, 'Evento de liquidacao sem parcela ou valor valido.');

            return;
        }

        $installment->refresh();
        $balance = round((float) $installment->balance_amount, 2);

        if ($amount > $balance) {
            $this->markForReview($event, 'Valor do evento excede o saldo da parcela.');

            return;
        }

        $payment = $receivableService->registerInstallmentPayment(
            $installment,
            $amount,
            $event->received_at?->toDateString() ?? now()->toDateString(),
            [
                'financial_account_id' => $installment->financial_account_id,
                'bank_slip_id' => $bankSlip->id,
                'bank_slip_event_id' => $event->id,
                'description' => 'Pagamento incremental de boleto bancario',
            ],
        );

        if ($receivableService->hasError() || ! $payment) {
            throw new RuntimeException(
                $receivableService->getMessage() ?: 'Nao foi possivel registrar o pagamento do boleto.'
            );
        }

        $installment->refresh();
        $bankSlip->update([
            'status' => (float) $installment->balance_amount <= 0
                ? BankSlipStatus::PAID->value
                : BankSlipStatus::PARTIALLY_PAID->value,
            'paid_at' => (float) $installment->balance_amount <= 0 ? now() : $bankSlip->paid_at,
            'provider_status_code' => (string) $event->provider_status_code,
            'provider_status_message' => data_get($event->payload, 'status.mensagem')
                ?? data_get($event->payload, 'status.message'),
            'last_synchronized_at' => now(),
            'provider_payload' => $event->payload,
            ...$this->providerDocumentAttributes($event, $bankSlip),
        ]);
        $event->update(['processed_at' => now()]);
    }

    /**
     * @return array<string, string|null>
     */
    private function providerDocumentAttributes(BankSlipEvent $event, BankSlip $bankSlip): array
    {
        return [
            'pdf_url' => data_get($event->payload, 'pdf')
                ?? data_get($event->payload, 'dados.pdf')
                ?? data_get($event->payload, 'data.pdf')
                ?: $bankSlip->pdf_url,
            'digitable_line' => data_get($event->payload, 'linha_digitavel')
                ?? data_get($event->payload, 'dados.linha_digitavel')
                ?? data_get($event->payload, 'data.linha_digitavel')
                ?: $bankSlip->digitable_line,
            'barcode' => data_get($event->payload, 'codigo_barras')
                ?? data_get($event->payload, 'dados.codigo_barras')
                ?? data_get($event->payload, 'data.codigo_barras')
                ?? data_get($event->payload, 'barcode')
                ?? data_get($event->payload, 'dados.barcode')
                ?? data_get($event->payload, 'data.barcode')
                ?? $bankSlip->barcode,
        ];
    }

    private function markForReview(BankSlipEvent $event, string $message): void
    {
        $event->update([
            'processed_at' => now(),
            'failed_at' => now(),
            'error' => $message,
        ]);

        $event->bankSlip?->update([
            'status' => BankSlipStatus::NEEDS_REVIEW->value,
            'provider_status_code' => $event->provider_status_code,
            'provider_payload' => $event->payload,
            'last_error' => $message,
            'last_synchronized_at' => now(),
        ]);

        Log::warning('ProcessBankSlipWebhookJob: evento encaminhado para revisao', [
            'event_id' => $event->id,
            'bank_slip_id' => $event->bank_slip_id,
            'message' => $message,
        ]);
    }
}
