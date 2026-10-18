<?php

namespace App\Services\Financial\Banking;

use App\Jobs\ProcessBankingEventJob;
use App\Models\BankingEvent;
use App\Models\BankSlip;
use App\Models\CashMovement;
use App\Models\PixCharge;
use App\Models\User;
use App\Services\Access\UserAccessService;
use App\Services\AccountReceivable\AccountReceivableService;
use App\Services\Audit\AuditRecorder;
use App\Support\Financial\InstallmentPaymentGuard;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class BankingResolutionService
{
    public function authorize(BankingEvent $event, int $companyId, User $user): void
    {
        $event = $event->fresh() ?? abort(404);
        abort_unless((int) $event->company_id === $companyId && $user->isActive()
            && app(UserAccessService::class)->allows($user, $event->company, 'account_receivables.access', Filament::getCurrentPanel()?->getId()), 403);
    }

    public function reprocess(BankingEvent $event, int $companyId, User $user, string $reason): void
    {
        $this->authorize($event, $companyId, $user);
        $this->reason($reason);
        DB::transaction(function () use ($event, $user, $reason): void {
            $event = BankingEvent::query()->lockForUpdate()->findOrFail($event->id);
            if (! in_array($event->status, ['failed', 'needs_review', 'pending'], true)) {
                throw ValidationException::withMessages(['event' => ['Evento já concluído.']]);
            }
            $before = app(AuditRecorder::class)->snapshot($event);
            $event->update(['status' => 'pending', 'processed_at' => null]);
            $this->audit($event, $before, $user, 'reprocess_requested', $reason);
            ProcessBankingEventJob::dispatch($event->id)->afterCommit();
        });
    }

    public function acknowledge(BankingEvent $event, int $companyId, User $user, string $reason): void
    {
        $this->authorize($event, $companyId, $user);
        $this->reason($reason);
        DB::transaction(function () use ($event, $reason, $user): void {
            $event = BankingEvent::query()->lockForUpdate()->findOrFail($event->id);
            if ($event->status !== 'needs_review' || (($event->bank_slip_id || $event->pix_charge_id)
                && in_array(data_get($event->payload, 'charge_status'), ['3', '7'], true))) {
                throw ValidationException::withMessages(['event' => ['Uma pendência financeira vinculada exige resolução do recebimento/devolução.']]);
            }
            $before = app(AuditRecorder::class)->snapshot($event);
            $event->update(['status' => 'ignored', 'processed_at' => now(), 'payload' => [...$event->payload, 'resolution' => ['reason' => $reason, 'financial_effect' => false]]]);
            $this->audit($event, $before, $user, 'event_acknowledged', $reason);
        });
    }

    public function confirmPayment(BankingEvent $event, int $companyId, User $user, array $data): void
    {
        $this->authorize($event, $companyId, $user);
        $validated = Validator::make($data, [
            'reason' => ['required', 'string', 'min:10', 'max:2000'], 'amount' => ['required', 'numeric', 'gt:0'],
            'payment_date' => ['required', 'date_format:Y-m-d'], 'payment_id' => ['nullable', 'integer'],
            'interest_amount' => ['nullable', 'numeric', 'min:0'], 'fine_amount' => ['nullable', 'numeric', 'min:0'], 'discount_amount' => ['nullable', 'numeric', 'min:0'],
        ])->validate();
        DB::transaction(function () use ($event, $validated, $user): void {
            [$event, $charge] = $this->lock($event, '3');
            $installment = InstallmentPaymentGuard::lock($charge->installment);
            $before = app(AuditRecorder::class)->snapshot($event);
            $field = $charge instanceof BankSlip ? 'bank_slip_id' : 'pix_charge_id';
            $account = $charge->financial_account_id ?? $charge->connection->financial_account_id;
            $reported = data_get($event->payload, 'response.valor');
            if ($event->source === 'webhook' && is_numeric($reported) && data_get($charge->connection->settings, 'webhook_amount_mode') === 'cumulative') {
                $represented = $installment->payments()->where($field, $charge->id)->whereNotNull('bank_confirmed_at')->get()
                    ->sum(fn ($payment) => (float) $payment->amount - (float) $payment->returned_amount);
                $reported = round((float) $reported - $represented, 2);
            }
            if ($event->source === 'webhook' && is_numeric($reported)
                && (int) round((float) $reported * 100) !== (int) round((float) $validated['amount'] * 100)) {
                throw ValidationException::withMessages(['amount' => ['O valor deve corresponder ao recebimento informado no webhook.']]);
            }
            $service = app(AccountReceivableService::class);
            if (! empty($validated['payment_id'])) {
                $payment = $installment->payments()->where('company_id', $event->company_id)->lockForUpdate()->findOrFail($validated['payment_id']);
                if ((int) round((float) $payment->amount * 100) !== (int) round((float) $validated['amount'] * 100)
                    || (int) $payment->financial_account_id !== (int) $account || (float) $payment->returned_amount > 0
                    || ($payment->{$field} && (int) $payment->{$field} !== (int) $charge->id)
                    || ($field === 'bank_slip_id' && $payment->pix_charge_id !== null)
                    || ($field === 'pix_charge_id' && $payment->bank_slip_id !== null)
                    || ($payment->banking_event_id !== null && $payment->bank_confirmed_at === null)
                    || ($payment->bank_confirmed_at !== null && (int) $payment->{$field} !== (int) $charge->id)) {
                    throw ValidationException::withMessages(['payment_id' => ['Baixa incompatível ou já confirmada por outro evento.']]);
                }
                $movement = CashMovement::query()->where('origin_type', $payment::class)->where('origin_id', $payment->id)->first();
                if (! $movement || $movement->reversed_at || (float) $movement->amount !== (float) $payment->amount
                    || (int) $movement->financial_account_id !== (int) $account || (int) $movement->company_id !== (int) $event->company_id) {
                    throw ValidationException::withMessages(['payment_id' => ['Movimento da baixa é incompatível.']]);
                }
                if ($payment->bank_confirmed_at === null) {
                    $payment->update([$field => $charge->id, 'banking_event_id' => $event->id, 'bank_confirmed_at' => now()]);
                }
                // Explicitly represented duplicate: retain the original event and cash history.
                $validated['represented_payment_id'] = $payment->id;
            } else {
                $payment = $service->registerInstallmentPayment($installment, (float) $validated['amount'], $validated['payment_date'], [
                    ...$validated, $field => $charge->id, 'banking_event_id' => $event->id,
                    'financial_account_id' => $account, 'user_id' => $user->id,
                    'notes' => $validated['reason'],
                ]);
                if (! $payment || $service->hasError()) {
                    throw ValidationException::withMessages(['payment' => [$service->getMessageUser()]]);
                }
                $payment->update(['bank_confirmed_at' => now()]);
            }
            $event->update(['status' => 'resolved', 'processed_at' => now(), 'error' => null, 'payload' => [...$event->payload, 'resolution' => $validated]]);
            $fullyCovered = app(BankSettlementService::class)->fullyCovered($charge);
            $charge->update(['status' => $fullyCovered ? 'paid' : 'partially_paid',
                'paid_at' => $fullyCovered ? ($charge->paid_at ?? $validated['payment_date']) : null, 'last_error' => null, 'operation_status' => 'confirmed']);
            $this->audit($event, $before, $user, 'payment_resolved', $validated['reason']);
        });
    }

    /** Register an already confirmed full bank return; this never sends money. */
    public function confirmReturn(BankingEvent $event, int $companyId, User $user, int $paymentId, string $date, string $reason): void
    {
        $this->authorize($event, $companyId, $user);
        $this->reason($reason);
        Validator::make(['date' => $date], ['date' => ['required', 'date_format:Y-m-d']])->validate();
        DB::transaction(function () use ($event, $user, $paymentId, $date, $reason): void {
            [$event, $charge] = $this->lock($event, '7');
            $installment = InstallmentPaymentGuard::lock($charge->installment);
            $field = $charge instanceof BankSlip ? 'bank_slip_id' : 'pix_charge_id';
            $payment = $installment->payments()->where('company_id', $event->company_id)->where($field, $charge->id)->lockForUpdate()->findOrFail($paymentId);
            if (! $payment->bank_confirmed_at || (float) $payment->returned_amount > 0) {
                throw ValidationException::withMessages(['payment_id' => ['Recebimento não confirmado ou já devolvido.']]);
            }
            $movement = CashMovement::query()->where('origin_type', $payment::class)->where('origin_id', $payment->id)->lockForUpdate()->firstOrFail();
            if ($movement->reversed_at || $movement->reversals()->exists()
                || (int) $movement->company_id !== (int) $payment->company_id
                || (int) $movement->financial_account_id !== (int) $payment->financial_account_id
                || (int) round($movement->amount * 100) !== (int) round($payment->amount * 100)
                || $date < $payment->payment_date->toDateString()) {
                throw ValidationException::withMessages(['movement' => ['Movimento já estornado.']]);
            }
            $before = app(AuditRecorder::class)->snapshot($event);
            $reversal = $movement->replicate(['origin_type', 'origin_id', 'transfer_group_id', 'reversed_at']);
            $reversal->fill([
                'direction' => 'outflow', 'amount' => $payment->amount, 'reversal_of_id' => $movement->id,
                'transaction_date' => $date, 'competence_date' => $date,
                'description' => 'Devolução bancária: '.$movement->description,
                'notes' => $reason, 'created_by' => $user->id, 'updated_by' => $user->id,
            ])->save();
            $movement->update(['reversed_at' => now()]);
            $payment->update(['returned_amount' => $payment->amount]);
            app(AccountReceivableService::class)->resynchronizeAfterBankReturn($installment);
            $event->update(['status' => 'resolved', 'processed_at' => now(), 'error' => null,
                'payload' => [...$event->payload, 'resolution' => ['payment_id' => $paymentId, 'return_date' => $date, 'reason' => $reason, 'cash_movement_id' => $reversal->id]]]);
            $charge->update(['status' => 'payment_returned', 'operation_status' => 'confirmed', 'last_error' => null]);
            $this->audit($event, $before, $user, 'return_resolved', $reason);
        });
    }

    private function lock(BankingEvent $event, string $state): array
    {
        $event = BankingEvent::query()->lockForUpdate()->findOrFail($event->id);
        if ($event->status !== 'needs_review' || (string) data_get($event->payload, 'charge_status') !== $state) {
            throw ValidationException::withMessages(['event' => ['Evento não está pendente desta resolução.']]);
        }
        $charge = $event->bank_slip_id ? BankSlip::query()->lockForUpdate()->findOrFail($event->bank_slip_id) : PixCharge::query()->lockForUpdate()->findOrFail($event->pix_charge_id);
        if ((int) $charge->company_id !== (int) $event->company_id || (int) $charge->bank_account_connection_id !== (int) $event->bank_account_connection_id
            || ! $charge->installment || (int) $charge->installment->company_id !== (int) $event->company_id
            || (int) $charge->connection?->company_id !== (int) $event->company_id) {
            throw ValidationException::withMessages(['company_id' => ['Empresa/conexão da cobrança divergente.']]);
        }

        return [$event, $charge];
    }

    private function reason(string $reason): void
    {
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'min:10', 'max:2000']])->validate();
    }

    private function audit(BankingEvent $event, array $before, User $user, string $action, string $reason): void
    {
        app(AuditRecorder::class)->recordModelEvent($event, 'banking.'.$action, $reason, $before,
            app(AuditRecorder::class)->snapshot($event->fresh()), $user->id);
    }
}
