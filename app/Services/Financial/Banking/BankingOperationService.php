<?php

namespace App\Services\Financial\Banking;

use App\Jobs\QueryBankSlipJob;
use App\Jobs\QueryPixChargeJob;
use App\Jobs\RegisterBankSlipJob;
use App\Jobs\RegisterPixChargeJob;
use App\Jobs\UpdateBankSlipJob;
use App\Models\BankingEvent;
use App\Models\BankSlip;
use App\Models\PixCharge;
use App\Models\User;
use App\Services\Access\UserAccessService;
use App\Services\Audit\AuditRecorder;
use App\Services\Financial\Banking\Providers\IntegraBancosPayloadBuilder;
use App\Support\Financial\InstallmentPaymentGuard;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class BankingOperationService
{
    /** Reserve before external I/O so a killed worker can be recovered by manual consultation. */
    public function beginRegistration(BankSlip|PixCharge $charge): bool
    {
        return DB::transaction(function () use ($charge): bool {
            $charge = $charge->newQuery()->lockForUpdate()->findOrFail($charge->id);
            if (! in_array($charge->status->value, ['pending_registration', 'registration_failed'], true)) {
                return false;
            }
            if ($charge->request_snapshot !== null && $charge->request_sent_at !== null) {
                $charge->update(['status' => 'uncertain', 'operation_status' => 'uncertain']);

                return false;
            }
            if ($charge->connection?->status !== 'active'
                || (int) $charge->connection->company_id !== (int) $charge->company_id
                || ($charge->financial_account_id && (int) $charge->financial_account_id !== (int) $charge->connection->financial_account_id)
                || ! $charge->installment || (float) $charge->installment->balance_amount <= 0
                || $charge->installment->accountReceivable?->status->value === 'cancelled') {
                $charge->update(['status' => 'needs_review', 'operation_status' => 'failed', 'last_error' => 'Conexão, conta ou parcela mudou antes do envio. Revise a emissão.']);
                $event = app(BankingEventService::class)->record($charge, ['response' => [], 'successful' => false, 'message' => $charge->last_error], 'register', 'preflight');
                $event->update(['status' => 'needs_review', 'error' => $charge->last_error]);

                return false;
            }
            $charge->update(['status' => 'processing', 'operation_kind' => 'register', 'operation_attempt_id' => (string) Str::uuid(), 'operation_status' => 'sending', 'request_sent_at' => now()]);
            $intent = app(BankingEventService::class)->record($charge, ['response' => [], 'successful' => true, 'response_code' => '4'], 'register', 'intent');
            $intent->update(['status' => 'processed', 'processed_at' => now()]);

            return true;
        });
    }

    public function query(BankSlip|PixCharge $charge): void
    {
        if ($charge instanceof BankSlip) {
            QueryBankSlipJob::dispatch($charge->id)->afterCommit();
        } else {
            QueryPixChargeJob::dispatch($charge->id)->afterCommit();
        }
    }

    public function requestUpdate(BankSlip $charge, int $companyId, User $user, string $reason): void
    {
        $charge = $charge->fresh() ?? abort(404);
        abort_unless((int) $charge->company_id === $companyId && app(UserAccessService::class)
            ->allows($user, $charge->company, 'account_receivables.access', Filament::getCurrentPanel()?->getId()), 403);
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'min:10', 'max:2000']])->validate();
        app(IntegraBancosPayloadBuilder::class)->assertOperation($charge->connection, 'update');
        DB::transaction(function () use ($charge, $reason, $user): void {
            $charge = BankSlip::query()->lockForUpdate()->findOrFail($charge->id);
            $installment = InstallmentPaymentGuard::lock($charge->installment);
            if ($charge->status->value !== 'registered' || $installment->payments()->exists()
                || (int) $installment->company_id !== (int) $charge->company_id
                || (int) $installment->accountReceivable->company_id !== (int) $charge->company_id
                || $installment->accountReceivable->status->value === 'cancelled') {
                throw ValidationException::withMessages(['charge' => ['Somente boletos gerados e sem baixas podem ser sincronizados.']]);
            }
            $before = app(AuditRecorder::class)->snapshot($charge);
            $charge->update([
                'status' => 'update_pending', 'operation_status' => 'ready', 'operation_kind' => 'update', 'operation_attempt_id' => (string) Str::uuid(), 'provider_operation_id' => null,
                'pending_update' => ['valor' => number_format($installment->balance_amount, 2, '.', ''), 'data_vencimento' => $installment->due_date->toDateString()],
            ]);
            $intent = app(BankingEventService::class)->record($charge, ['response' => [], 'successful' => true, 'response_code' => '4'], 'update', 'intent');
            $intent->update(['status' => 'processed', 'processed_at' => now()]);
            app(AuditRecorder::class)->recordModelEvent($charge, 'banking.update_requested', $reason, $before,
                app(AuditRecorder::class)->snapshot($charge->fresh()), $user->id);
            UpdateBankSlipJob::dispatch($charge->id)->afterCommit();
        });
    }

    public function retryRegistration(BankSlip|PixCharge $charge, int $companyId, User $user, string $reason): void
    {
        $charge = $charge->fresh() ?? abort(404);
        abort_unless((int) $charge->company_id === $companyId && app(UserAccessService::class)
            ->allows($user, $charge->company, 'account_receivables.access', Filament::getCurrentPanel()?->getId()), 403);
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'min:10', 'max:2000']])->validate();
        DB::transaction(function () use ($charge, $user, $reason): void {
            $charge = $charge->newQuery()->lockForUpdate()->findOrFail($charge->id);
            $localFailure = ($charge->status->value === 'registration_failed' && ($charge->request_snapshot === null || in_array($charge->provider_response_code, ['1', '23', 'LOCAL_VALIDATION'], true)))
                || ($charge->status->value === 'pending_registration' && $charge->request_sent_at === null);
            $field = $charge instanceof BankSlip ? 'bank_slip_id' : 'pix_charge_id';
            $evidence = BankingEvent::query()->where($field, $charge->id)->where('source', 'query')
                ->where('operation', 'query')->latest('id')->first();
            $notFound = $charge->status->value === 'uncertain' && $charge->provider_response_code === '12'
                && $charge->request_sent_at?->lte(now()->subMinutes(5)) && $evidence
                && (string) data_get($evidence->payload, 'response_code') === '12' && $evidence->deliveries >= 2
                && $evidence->last_received_at?->gte($evidence->received_at->copy()->addMinute());
            if ((! $localFailure && ! $notFound) || ! $charge->installment
                || (int) $charge->installment->company_id !== (int) $charge->company_id
                || $charge->installment->payments()->exists()) {
                throw ValidationException::withMessages(['charge' => ['Reenvio exige falha de validação anterior ao processamento ou ausência confirmada em duas consultas espaçadas, após cinco minutos.']]);
            }
            $audit = app(AuditRecorder::class);
            $before = $audit->snapshot($charge);
            $charge->update([
                'status' => 'pending_registration', 'operation_status' => 'ready', 'request_sent_at' => null,
                'request_snapshot' => $localFailure ? null : $charge->request_snapshot, 'last_error' => null,
            ]);
            $audit->recordModelEvent($charge, 'banking.registration_retry_requested', $reason, $before, $audit->snapshot($charge->fresh()), $user->id);
            if ($charge instanceof BankSlip) {
                RegisterBankSlipJob::dispatch($charge->id)->afterCommit();
            } else {
                RegisterPixChargeJob::dispatch($charge->id)->afterCommit();
            }
        });
    }
}
