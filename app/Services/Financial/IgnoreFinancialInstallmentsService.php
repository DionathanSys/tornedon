<?php

namespace App\Services\Financial;

use App\Models\AccountPayable;
use App\Models\AccountPayableInstallment;
use App\Models\AccountReceivable;
use App\Models\AccountReceivableInstallment;
use App\Models\Company;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Support\Financial\InstallmentPaymentGuard;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class IgnoreFinancialInstallmentsService
{
    public function change(Collection $records, bool $ignore, string $reason, User $user, Company $company, ?string $panel): int
    {
        Validator::make(['reason' => $reason], ['reason' => 'required|string|min:5|max:1000'])->validate();

        return DB::transaction(function () use ($records, $ignore, $reason, $user, $company, $panel): int {
            $count = 0;
            $audit = app(AuditRecorder::class);
            // Keep the same lock order as payment registration: account, then installment.
            $records = $records->sortBy(fn ($record) => sprintf('%s:%020d:%020d', $record::class, $record->account_payable_id ?? $record->account_receivable_id ?? $record->id, $record->id));
            foreach ($records as $record) {
                abort_unless($record instanceof AccountPayableInstallment || $record instanceof AccountReceivableInstallment
                    || $record instanceof AccountPayable || $record instanceof AccountReceivable, 403);
                $payable = $record instanceof AccountPayable || $record instanceof AccountPayableInstallment;
                $model = $payable ? AccountPayableInstallment::class : AccountReceivableInstallment::class;
                abort_unless(app(ChangeInstallmentStatusService::class)->allows($user, $company, $model, $panel), 403);
                abort_unless((int) $record->company_id === (int) $company->id, 403);

                if ($record instanceof AccountPayable || $record instanceof AccountReceivable) {
                    $account = $record->newQuery()->where('company_id', $company->id)->lockForUpdate()->findOrFail($record->id);
                    $installments = $account->installments()->where('company_id', $company->id)->orderBy('id')->lockForUpdate()->get();
                } else {
                    $installments = new Collection([InstallmentPaymentGuard::lock($record)]);
                }
                foreach ($installments as $installment) {
                    abort_unless((int) $installment->company_id === (int) $company->id, 403);
                    $parent = $payable ? $installment->accountPayable : $installment->accountReceivable;
                    abort_unless($parent && (int) $parent->company_id === (int) $company->id, 403);
                    if ((bool) $installment->ignored_at === $ignore) {
                        continue;
                    }
                    if ($ignore && ($installment->status->value === 'cancelled' || (float) $installment->balance_amount <= 0)) {
                        continue;
                    }
                    if ($ignore && $installment instanceof AccountReceivableInstallment
                        && ($installment->bankSlips()->whereNotIn('status', ['canceled', 'registration_failed'])->exists()
                            || $installment->pixCharges()->whereNotIn('status', ['canceled', 'registration_failed', 'expired'])->exists())) {
                        throw ValidationException::withMessages(['reason' => 'Cancele ou trate as cobranças bancárias vinculadas antes de ignorar a parcela.']);
                    }
                    $before = $audit->snapshot($installment);
                    $installment->forceFill([
                        'ignored_at' => $ignore ? now() : null,
                        'ignored_by' => $ignore ? $user->id : null,
                        'ignored_reason' => $ignore ? $reason : null,
                    ])->save();
                    $audit->recordModelEvent($installment, $ignore ? 'financial_installment.ignored' : 'financial_installment.restored',
                        $ignore ? 'Parcela desconsiderada' : 'Parcela voltou a ser considerada', $before, $audit->snapshot($installment), $user->id,
                        metadata: ['reason' => $reason]);
                    $count++;
                }
            }

            return $count;
        });
    }
}
