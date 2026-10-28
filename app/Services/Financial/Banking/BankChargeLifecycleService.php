<?php

namespace App\Services\Financial\Banking;

use App\Jobs\CancelBankSlipJob;
use App\Jobs\CancelPixChargeJob;
use App\Models\BankingEvent;
use App\Models\BankSlip;
use App\Models\PixCharge;
use App\Models\User;
use App\Services\Access\UserAccessService;
use App\Services\Audit\AuditRecorder;
use App\Services\Financial\Banking\Providers\IntegraBancosPayloadBuilder;
use App\Services\Financial\Pix\PixProviderRegistry;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class BankChargeLifecycleService
{
    public function cancel(BankSlip|PixCharge $charge, int $companyId, User $user, string $reason): void
    {
        $this->change($charge, $companyId, $user, $reason, 'cancel_requested', function ($charge): void {
            if ($charge->trashed() || ! in_array($charge->status->value, ['registered', 'expired', 'cancel_pending'], true)
                || ($charge->status->value === 'cancel_pending' && $charge->operation_status !== 'failed')
                || in_array($charge->operation_status, ['sending', 'pending', 'uncertain'], true)) {
                $this->invalid('A cobrança não pode ser cancelada neste estado. Consulte o provedor para atualizar uma solicitação pendente.');
            }
            $this->assertNoFinancialEffects($charge);
            if ($charge->connection?->status !== 'active' || (int) $charge->connection->company_id !== (int) $charge->company_id
                || blank($charge->provider_identification)) {
                $this->invalid('A cobrança precisa de identificação e conexão bancária ativa da mesma empresa.');
            }
            if ($charge instanceof BankSlip) {
                app(IntegraBancosPayloadBuilder::class)->assertOperation($charge->connection, 'cancel');
            } else {
                app(PixProviderRegistry::class)->resolve($charge->connection)->assertCancellationSupported();
            }
            $charge->update(['status' => 'cancel_pending', 'cancel_requested_at' => now(), 'operation_kind' => 'cancel', 'operation_status' => 'ready', 'provider_operation_id' => null, 'last_error' => null]);
            if ($charge instanceof BankSlip) {
                CancelBankSlipJob::dispatch($charge->id)->afterCommit();
            } else {
                CancelPixChargeJob::dispatch($charge->id)->afterCommit();
            }
        });
    }

    public function remove(BankSlip|PixCharge $charge, int $companyId, User $user, string $reason, bool $permanent = false): void
    {
        $this->change($charge, $companyId, $user, $reason, $permanent ? 'permanently_removed' : 'removed', function ($charge) use ($permanent, $user): void {
            $unsent = in_array($charge->status->value, ['pending_registration', 'registration_failed'], true)
                && $charge->request_sent_at === null && $charge->request_snapshot === null;
            // request_sent_at is reserved before the call, including requests that
            // are explicitly refused by validation/authentication without issuance.
            $rejectedRegistration = in_array($charge->status->value, ['registration_failed', 'processing'], true)
                && $charge->operation_status === 'failed'
                && $charge->operation_kind === 'register'
                && $charge->registered_at === null && $charge->paid_at === null
                && blank($charge->provider_charge_id)
                && ! in_array((string) $charge->provider_status_code, ['2', '3', '7'], true);
            if ($charge->status->value !== 'canceled' && ! $unsent && ! $rejectedRegistration) {
                $this->invalid('Confirme o cancelamento no provedor antes de remover a cobrança.');
            }
            $this->resolveObsoleteOperationalEvents($charge, $user);
            $this->assertNoFinancialEffects($charge);
            if ($permanent) {
                if (! $charge->trashed()) {
                    $this->invalid('Remova a cobrança para a lixeira antes de excluir definitivamente.');
                }
                // Preserve bank history even though the charge itself is permanently removed.
                foreach ($this->events($charge)->lockForUpdate()->get() as $event) {
                    $event->update([
                        'payload' => [...$event->payload, 'removed_charge' => app(AuditRecorder::class)->snapshot($charge)],
                        $this->field($charge) => null,
                    ]);
                }
                $charge->forceDelete();
            } else {
                if ($charge->trashed()) {
                    $this->invalid('A cobrança já está na lixeira.');
                }
                $charge->delete();
            }
        });
    }

    private function change(BankSlip|PixCharge $charge, int $companyId, User $user, string $reason, string $action, \Closure $callback): void
    {
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'min:10', 'max:2000']])->validate();
        DB::transaction(function () use ($charge, $companyId, $user, $reason, $action, $callback): void {
            $charge = $charge->newQuery()->withTrashed()->lockForUpdate()->findOrFail($charge->id);
            abort_unless((int) $charge->company_id === $companyId && $user->isActive()
                && app(UserAccessService::class)->allows($user, $charge->company, 'account_receivables.access', Filament::getCurrentPanel()?->getId()), 403);
            $audit = app(AuditRecorder::class);
            $before = $audit->snapshot($charge);
            $callback($charge);
            $audit->recordModelEvent($charge, 'banking.'.$action, $reason, $before, $charge->exists ? $audit->snapshot($charge) : [], $user->id);
        });
    }

    private function assertNoFinancialEffects(BankSlip|PixCharge $charge): void
    {
        if (in_array($charge->status->value, ['paid', 'partially_paid', 'payment_returned'], true)
            || DB::table('account_receivable_installment_payments')->where($this->field($charge), $charge->id)->exists()
            || ($charge instanceof BankSlip && $charge->events()->whereNull('processed_at')->exists())
            || $this->events($charge)->whereIn('status', ['pending', 'failed', 'needs_review'])->exists()) {
            $this->invalid('Trate os recebimentos e eventos bancários pendentes antes desta ação.');
        }
    }

    private function resolveObsoleteOperationalEvents(BankSlip|PixCharge $charge, User $user): void
    {
        if ($charge->status->value !== 'canceled') {
            return;
        }

        // A processed charge cancellation is evidence; a completed operation alone
        // (event_query) does not confirm that the bank canceled the charge.
        $confirmation = $this->events($charge)
            ->where('bank_account_connection_id', $charge->bank_account_connection_id)
            ->where('status', 'processed')->where('operation', '!=', 'event_query')
            ->orderByDesc('id')->get()->first(fn (BankingEvent $event): bool => data_get($event->payload, 'successful') === true
                && (string) data_get($event->payload, 'charge_status') === '4');
        if (! $confirmation) {
            return;
        }

        $resolution = [
            'reason' => 'Pendência operacional anterior superada pelo cancelamento confirmado no provedor.',
            'cancellation_event_id' => $confirmation->id,
            'financial_effect' => false,
        ];
        $audit = app(AuditRecorder::class);
        $events = $this->events($charge)
            ->where('bank_account_connection_id', $charge->bank_account_connection_id)
            ->where('id', '<', $confirmation->id)->whereIn('status', ['pending', 'failed'])
            ->whereIn('operation', ['register', 'cancel', 'update', 'query', 'event_query', 'GERACAO', 'ATUALIZACAO', 'CANCELAMENTO'])
            ->whereIn('source', ['request', 'intent', 'query', 'webhook'])->lockForUpdate()->get();

        foreach ($events as $event) {
            $state = (string) data_get($event->payload, 'charge_status');
            $pendingCancellation = $state === '' && $event->operation === 'cancel'
                && (string) data_get($event->payload, 'response_code') === '4';
            // Never dismiss payment, return, rejection, or unknown-state events.
            if (! in_array($state, ['0', '1', '2', '4', '5', '6'], true) && ! $pendingCancellation) {
                continue;
            }
            $before = $audit->snapshot($event);
            $event->update(['status' => 'resolved', 'processed_at' => now(),
                'payload' => [...$event->payload, 'resolution' => $resolution]]);
            $audit->recordModelEvent($event, 'banking.obsolete_operation_resolved', $resolution['reason'],
                $before, $audit->snapshot($event), $user->id);
        }

        if ($charge instanceof BankSlip) {
            $legacyEvents = $charge->events()->where('company_id', $charge->company_id)
                ->where('bank_account_connection_id', $charge->bank_account_connection_id)
                ->whereNull('processed_at')->where('received_at', '<=', $confirmation->received_at)
                ->whereIn('provider_status_code', ['0', '1', '2', '4', '5', '6'])->lockForUpdate()->get();
            foreach ($legacyEvents as $event) {
                $before = $audit->snapshot($event);
                $event->update(['processed_at' => now(), 'payload' => [...$event->payload, 'resolution' => $resolution]]);
                $audit->recordModelEvent($event, 'banking.obsolete_operation_resolved', $resolution['reason'],
                    $before, $audit->snapshot($event), $user->id);
            }
        }
    }

    private function events(BankSlip|PixCharge $charge): Builder
    {
        return BankingEvent::query()->where('company_id', $charge->company_id)->where($this->field($charge), $charge->id);
    }

    private function field(BankSlip|PixCharge $charge): string
    {
        return $charge instanceof BankSlip ? 'bank_slip_id' : 'pix_charge_id';
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['charge' => [$message]]);
    }
}
