<?php

namespace App\Services\Financial\Banking;

use App\Enum\Financial\BankSlipStatus;
use App\Enum\Payment\Method as PaymentMethod;
use App\Jobs\RegisterBankSlipJob;
use App\Models\AccountReceivableInstallment;
use App\Models\BankSlip;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class BankSlipIssuanceService
{
    public function __construct(
        private readonly BankSlipEligibilityService $eligibilityService,
    ) {}

    public function scheduleForInvoice(Invoice $invoice): int
    {
        $invoice->loadMissing('accountReceivables.installments');

        if (
            ! $invoice->confirmed
            || $invoice->canceled
            || $invoice->payment_method !== PaymentMethod::BANK_SLIP
            || $invoice->auto_bank_slip_issuance !== true
        ) {
            return 0;
        }

        $scheduled = 0;

        foreach ($invoice->accountReceivables as $receivable) {
            foreach ($receivable->installments as $installment) {
                if ($installment->auto_bank_slip_issuance !== true) {
                    continue;
                }

                $bankSlip = $this->prepareBankSlip($installment);

                if (! $bankSlip) {
                    continue;
                }

                // This service is called from ScheduleBankSlipIssuanceJob, which already runs after commit.
                RegisterBankSlipJob::dispatch($bankSlip->id);
                $scheduled++;
            }
        }

        return $scheduled;
    }

    public function prepareBankSlip(AccountReceivableInstallment $installment): ?BankSlip
    {
        $bankSlip = DB::transaction(function () use ($installment): ?BankSlip {
            $installment = AccountReceivableInstallment::query()
                ->with('accountReceivable')
                ->lockForUpdate()
                ->find($installment->id);

            if (! $installment || $installment->auto_bank_slip_issuance !== true) {
                return null;
            }

            $existing = BankSlip::query()
                ->where('account_receivable_installment_id', $installment->id)
                ->whereNotIn('status', [
                    BankSlipStatus::CANCELED->value,
                    BankSlipStatus::PAYMENT_RETURNED->value,
                ])
                ->latest('id')
                ->first();

            if ($existing) {
                return $existing;
            }

            try {
                $connection = $this->eligibilityService->resolveConnection($installment);
            } catch (ValidationException $exception) {
                Log::warning('BankSlipIssuanceService: parcela sem elegibilidade para boleto', [
                    'installment_id' => $installment->id,
                    'company_id' => $installment->company_id,
                    'errors' => $exception->errors(),
                ]);

                return null;
            }

            return BankSlip::create([
                'company_id' => $installment->company_id,
                'account_receivable_installment_id' => $installment->id,
                'bank_account_connection_id' => $connection->id,
                'status' => BankSlipStatus::PENDING_REGISTRATION->value,
                'provider_identification' => $this->buildProviderIdentification($installment),
                'amount' => $installment->due_amount,
                'due_date' => $installment->due_date,
            ]);
        });

        return $bankSlip;
    }

    private function buildProviderIdentification(AccountReceivableInstallment $installment): string
    {
        return sprintf(
            'BS-%d-%s-%s',
            $installment->company_id,
            $installment->id,
            Str::lower(Str::random(8)),
        );
    }
}
