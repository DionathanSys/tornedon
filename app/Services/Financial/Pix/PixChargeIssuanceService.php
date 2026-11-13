<?php

namespace App\Services\Financial\Pix;

use App\Enum\Financial\PixChargeStatus;
use App\Enum\Payment\Method as PaymentMethod;
use App\Jobs\RegisterPixChargeJob;
use App\Models\AccountReceivableInstallment;
use App\Models\Invoice;
use App\Models\PixCharge;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PixChargeIssuanceService
{
    public function __construct(
        private readonly PixEligibilityService $eligibilityService,
    ) {}

    public function scheduleForInvoice(Invoice $invoice): int
    {
        $invoice->loadMissing('accountReceivables.installments');

        if (
            ! $invoice->confirmed
            || $invoice->canceled
            || $invoice->operations()->where('active_slot', 1)->exists()
            || $invoice->auto_pix_charge_issuance !== true
        ) {
            return 0;
        }

        $scheduled = 0;

        foreach ($invoice->accountReceivables as $receivable) {
            foreach ($receivable->installments as $installment) {
                if ($installment->payment_method !== PaymentMethod::PIX) {
                    continue;
                }

                if ($installment->auto_pix_charge_issuance === null) {
                    $installment->update(['auto_pix_charge_issuance' => true]);
                }

                if ($installment->auto_pix_charge_issuance !== true) {
                    continue;
                }

                $charge = $this->preparePixCharge($installment);

                if (! $charge) {
                    continue;
                }

                RegisterPixChargeJob::dispatch($charge->id);
                $scheduled++;
            }
        }

        return $scheduled;
    }

    public function preparePixCharge(
        AccountReceivableInstallment $installment,
        bool $requireAutomaticIssuance = false,
    ): ?PixCharge {
        return DB::transaction(function () use ($installment, $requireAutomaticIssuance): ?PixCharge {
            $installment = AccountReceivableInstallment::query()
                ->considered()
                ->with('accountReceivable')
                ->lockForUpdate()
                ->find($installment->id);

            if (! $installment || ($requireAutomaticIssuance && $installment->auto_pix_charge_issuance !== true)) {
                return null;
            }
            if ($installment->accountReceivable?->invoice?->operations()->where('active_slot', 1)->exists()) {
                return null;
            }

            $this->validateDueDate($installment);

            $existing = PixCharge::query()
                ->where('account_receivable_installment_id', $installment->id)
                ->whereNotIn('status', [
                    PixChargeStatus::CANCELED->value,
                    PixChargeStatus::EXPIRED->value,
                ])
                ->latest('id')
                ->first();

            if ($existing) {
                if ($existing->status === PixChargeStatus::REGISTRATION_FAILED
                    && ($existing->request_sent_at === null || $existing->request_snapshot === null
                        || in_array($existing->provider_response_code, ['1', '23', 'LOCAL_VALIDATION'], true))) {
                    $this->syncRegistrationData($existing, $installment);
                    $existing->update([
                        'status' => PixChargeStatus::PENDING_REGISTRATION,
                        'request_sent_at' => null,
                        'last_error' => null,
                    ]);
                } elseif ($existing->status === PixChargeStatus::PENDING_REGISTRATION && $existing->request_sent_at === null
                    && ($existing->request_snapshot === null || $existing->provider_response_code !== '12')) {
                    $this->syncRegistrationData($existing, $installment);
                }

                return $existing;
            }

            try {
                $connection = $this->eligibilityService->resolveConnection($installment);
            } catch (ValidationException $exception) {
                Log::warning('PixChargeIssuanceService: parcela sem elegibilidade para PIX', [
                    'installment_id' => $installment->id,
                    'company_id' => $installment->company_id,
                    'errors' => $exception->errors(),
                ]);

                return null;
            }

            return PixCharge::create([
                'company_id' => $installment->company_id,
                'account_receivable_installment_id' => $installment->id,
                'bank_account_connection_id' => $connection->id,
                'financial_account_id' => $connection->financial_account_id,
                'status' => PixChargeStatus::PENDING_REGISTRATION,
                'provider_identification' => $this->buildProviderIdentification($installment),
                'amount' => $installment->balance_amount,
                'due_date' => $installment->due_date,
            ]);
        });
    }

    public function validateDueDate(AccountReceivableInstallment $installment): void
    {
        if (! $installment->due_date || $installment->due_date->lt(today())) {
            throw ValidationException::withMessages([
                'due_date' => ['Para emitir PIX, o vencimento da parcela deve ser hoje ou uma data futura.'],
            ]);
        }
    }

    /** Only call before the first send or after a definitive registration rejection. */
    public function syncRegistrationData(PixCharge $charge, AccountReceivableInstallment $installment): void
    {
        $this->validateDueDate($installment);
        $charge->update([
            'amount' => $installment->balance_amount,
            'due_date' => $installment->due_date,
            'request_snapshot' => null,
        ]);
    }

    private function buildProviderIdentification(AccountReceivableInstallment $installment): string
    {
        return sprintf(
            'PX-%d-%s-%s',
            $installment->company_id,
            $installment->id,
            Str::lower(Str::random(8)),
        );
    }
}
