<?php

namespace App\Services\Hr;

use App\Models\AccountPayable;
use App\Models\AccountPayableInstallment;
use App\Models\AccountPayableInstallmentPayment;
use App\Models\HrEntry;
use App\Models\HrPerson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class HrFinancialGuard
{
    public function register(): void
    {
        AccountPayableInstallmentPayment::updating(fn ($payment) => $this->changingPayment($payment, false));
        AccountPayableInstallmentPayment::deleting(fn ($payment) => $this->changingPayment($payment, true));
        AccountPayable::updating(function (AccountPayable $payable): void {
            if ($payable->isDirty(['supplier_id', 'company_id']) && $this->entry($payable->id)) {
                $this->fail('A contraparte e a empresa do título de RH não podem ser alteradas.');
            }
        });
        AccountPayable::deleting(function (AccountPayable $payable): void {
            if ($this->entry($payable->id)) {
                $this->fail('Cancele o lançamento pelo RH para preservar o histórico.');
            }
        });
        AccountPayableInstallment::creating(function (AccountPayableInstallment $installment): void {
            if ($this->entry($installment->account_payable_id)) {
                $this->fail('O parcelamento de um título de RH é definido na confirmação do lançamento.');
            }
        });
        AccountPayableInstallment::updating(function (AccountPayableInstallment $installment): void {
            if ($installment->isDirty(['original_amount', 'competence_date', 'company_id', 'account_payable_id']) && $this->entry($installment->getRawOriginal('account_payable_id'))) {
                $this->fail('O valor original e a competência são definidos pelo lançamento de RH.');
            }
        });
        AccountPayableInstallment::deleting(function (AccountPayableInstallment $installment): void {
            if ($this->entry($installment->account_payable_id)) {
                $this->fail('Parcelas de RH não podem ser excluídas. Cancele o lançamento pelo RH.');
            }
        });
    }

    private function changingPayment(AccountPayableInstallmentPayment $payment, bool $deleting): void
    {
        $installment = DB::table('account_payable_installments')->find($payment->getRawOriginal('account_payable_installment_id'));
        $entry = $installment ? $this->entry($installment->account_payable_id) : null;
        if (! $entry) {
            return;
        }
        if ($payment->isDirty(['company_id', 'account_payable_installment_id'])) {
            $this->fail('A empresa e a parcela de uma baixa de RH não podem ser alteradas.');
        }
        // Serializes consumption and reversal of advances, including calls made directly by finance.
        HrPerson::withoutGlobalScopes()->whereKey($entry->hr_person_id)->lockForUpdate()->firstOrFail();
        if ($entry->nature === 'advance') {
            // Use current locking reads after acquiring the person lock, also on MySQL REPEATABLE READ.
            $used = DB::table('hr_advance_allocations')->where('advance_entry_id', $entry->id)->lockForUpdate()->get()->sum('amount');
            $payments = app(HrService::class)->payments($entry)->lockForUpdate()->get();
            $remaining = $payments->where('id', '!=', $payment->id)->sum(fn ($p) => max(0, HrService::cents($p->amount) - HrService::cents($p->interest_amount) - HrService::cents($p->fine_amount)));
            if (! $deleting) {
                $remaining += max(0, HrService::cents($payment->amount) - HrService::cents($payment->interest_amount) - HrService::cents($payment->fine_amount));
            }
            if ($remaining < HrService::cents($used)) {
                $this->fail('Este adiantamento já foi abatido. Cancele as compensações no RH antes de reduzir ou estornar o pagamento.');
            }
        }
        if ($deleting || $payment->isDirty()) {
            DB::table('hr_receipts')->where('payment_id', $payment->id)->whereNull('voided_at')->update(['voided_at' => now(), 'updated_at' => now()]);
        }
    }

    private function entry(?int $payableId): ?HrEntry
    {
        return $payableId && Schema::hasTable('hr_entries') ? HrEntry::withoutGlobalScopes()->where('account_payable_id', $payableId)->first() : null;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['hr' => $message]);
    }
}
