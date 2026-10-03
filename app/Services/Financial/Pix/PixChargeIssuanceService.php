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
            || $invoice->payment_method !== PaymentMethod::PIX
            || $invoice->auto_pix_charge_issuance !== true
        ) {
            return 0;
        }

        $scheduled = 0;

        foreach ($invoice->accountReceivables as $receivable) {
            foreach ($receivable->installments as $installment) {
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
                ->with('accountReceivable')
                ->lockForUpdate()
                ->find($installment->id);

            if (! $installment || ($requireAutomaticIssuance && $installment->auto_pix_charge_issuance !== true)) {
                return null;
            }

            $existing = PixCharge::query()
                ->where('account_receivable_installment_id', $installment->id)
                ->whereNotIn('status', [
                    PixChargeStatus::CANCELED->value,
                    PixChargeStatus::EXPIRED->value,
                ])
                ->latest('id')
                ->first();

            if ($existing) {
                if ($existing->status === PixChargeStatus::REGISTRATION_FAILED) {
                    $existing->update([
                        'status' => PixChargeStatus::PENDING_REGISTRATION,
                        'last_error' => null,
                    ]);
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
                'status' => PixChargeStatus::PENDING_REGISTRATION,
                'provider_identification' => $this->buildProviderIdentification($installment),
                'amount' => $installment->balance_amount,
                'due_date' => $installment->due_date,
            ]);
        });
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
