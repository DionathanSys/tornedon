<?php

namespace App\Jobs;

use App\Enum\Financial\PixChargeStatus;
use App\Models\BankAccountConnection;
use App\Models\PixCharge;
use App\Services\AccountReceivable\AccountReceivableService;
use App\Services\Financial\Pix\DTO\PixProviderResult;
use App\Services\Financial\Pix\PixProviderRegistry;
use App\Support\Financial\BankReceiptMatcher;
use App\Support\Financial\InstallmentPaymentGuard;
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

final class QueryPixChargeJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

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
        $lock = Cache::lock('pix-charge-query:'.$this->pixChargeId, 120);

        if (! $lock->get()) {
            return;
        }

        try {
            $charge = PixCharge::query()
                ->with(['connection.billingProvider', 'connection.bank'])
                ->find($this->pixChargeId);

            if (! $charge || blank($charge->provider_identification) || $charge->status !== PixChargeStatus::REGISTERED) {
                return;
            }

            $connection = $charge->connection;

            if (! $connection instanceof BankAccountConnection) {
                return;
            }

            $result = $registry->resolve($connection)->query($charge);
            $message = $result->message ?: implode('; ', $result->errors);

            $charge->update([
                'provider_identification' => $result->providerIdentification ?: $charge->provider_identification,
                'provider_charge_id' => $result->providerChargeId ?: $charge->provider_charge_id,
                'qr_code' => $result->qrCode ?: $charge->qr_code,
                'pix_copy_paste' => $result->pixCopyPaste ?: $charge->pix_copy_paste,
                'provider_status_code' => $result->statusCode,
                'provider_status_message' => $result->statusMessage,
                'last_synchronized_at' => now(),
                'provider_payload' => $result->payload,
                'last_error' => $result->successful ? null : $message,
            ]);

            if ($result->successful && $result->paid) {
                $this->registerPayment($result);
            } elseif ($result->successful) {
                $charge->update(['status' => $this->statusFromResult($result, $charge->status)]);
            }

            if ($result->retryable) {
                throw new RuntimeException($message ?: 'Falha transitória ao consultar cobrança PIX.');
            }
        } finally {
            $lock->release();
        }
    }

    public function failed(\Throwable $exception): void
    {
        PixCharge::query()
            ->whereKey($this->pixChargeId)
            ->update([
                'last_error' => $exception->getMessage(),
                'last_synchronized_at' => now(),
            ]);

        Log::error('QueryPixChargeJob: falha definitiva ao consultar cobrança PIX', [
            'pix_charge_id' => $this->pixChargeId,
            'exception' => $exception->getMessage(),
        ]);
    }

    private function registerPayment(PixProviderResult $result): void
    {
        DB::transaction(function () use ($result): void {
            $charge = PixCharge::query()
                ->with('installment')
                ->lockForUpdate()
                ->find($this->pixChargeId);

            if (! $charge || $charge->status === PixChargeStatus::PAID || ! $charge->installment) {
                return;
            }

            $amount = round($result->amount ?? (float) $charge->amount, 2);
            $expectedAmount = round((float) $charge->amount, 2);

            if ((int) round($amount * 100) !== (int) round($expectedAmount * 100)) {
                $charge->update([
                    'status' => PixChargeStatus::NEEDS_REVIEW,
                    'last_error' => sprintf(
                        'Valor liquidado pelo provider (R$ %.2f) diverge da cobrança (R$ %.2f).',
                        $amount,
                        $expectedAmount,
                    ),
                ]);

                return;
            }

            $installment = InstallmentPaymentGuard::lock($charge->installment);
            $connection = $charge->connection;
            if (! $connection || (int) $connection->company_id !== (int) $installment->company_id
                || $installment->status->value === 'cancelled' || $installment->accountReceivable->status->value === 'cancelled') {
                $charge->update(['status' => PixChargeStatus::NEEDS_REVIEW, 'last_error' => 'Conta, conexão ou parcela inválida para a liquidação.']);

                return;
            }
            $existing = BankReceiptMatcher::dueDatePayment(
                $installment, 'pix_charge_id', $charge->id, $amount, (int) ($charge->financial_account_id ?? $connection->financial_account_id),
            );
            if ($existing) {
                $existing->update(['bank_confirmed_at' => now()]);
                $charge->update(['status' => PixChargeStatus::PAID, 'paid_at' => now(), 'last_error' => null]);

                return;
            }
            if ((int) round($amount * 100) > (int) round((float) $installment->balance_amount * 100)) {
                $charge->update(['status' => PixChargeStatus::NEEDS_REVIEW, 'last_error' => 'Liquidação excede o saldo atual. Verifique baixas anteriores ou pagamento excedente.']);

                return;
            }

            $receivableService = app(AccountReceivableService::class);
            $payment = $receivableService->registerInstallmentPayment(
                $installment,
                $amount,
                now()->toDateString(),
                [
                    'financial_account_id' => $charge->financial_account_id ?? $connection->financial_account_id,
                    'pix_charge_id' => $charge->id,
                    'description' => 'Recebimento automático de cobrança PIX '.$charge->provider_identification,
                ],
            );

            if ($receivableService->hasError() || ! $payment) {
                throw new RuntimeException(
                    $receivableService->getMessage() ?: 'Falha ao registrar o recebimento da cobrança PIX.'
                );
            }

            $payment->update(['bank_confirmed_at' => now()]);

            $charge->update([
                'status' => PixChargeStatus::PAID,
                'paid_at' => now(),
                'last_error' => null,
            ]);
        });
    }

    private function statusFromResult(PixProviderResult $result, PixChargeStatus $current): PixChargeStatus
    {
        $status = strtolower((string) ($result->statusCode ?: $result->statusMessage));

        return match (true) {
            in_array($status, ['4', 'cancelado', 'cancelada', 'canceled'], true) => PixChargeStatus::CANCELED,
            in_array($status, ['5', 'expirado', 'expirada', 'expired'], true) => PixChargeStatus::EXPIRED,
            default => $current,
        };
    }
}
