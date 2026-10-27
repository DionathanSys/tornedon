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
        $this->change($charge, $companyId, $user, $reason, $permanent ? 'permanently_removed' : 'removed', function ($charge) use ($permanent): void {
            $this->assertNoFinancialEffects($charge);
            $unsent = in_array($charge->status->value, ['pending_registration', 'registration_failed'], true)
                && $charge->request_sent_at === null && $charge->request_snapshot === null;
            if ($charge->status->value !== 'canceled' && ! $unsent) {
                $this->invalid('Confirme o cancelamento no provedor antes de remover a cobrança.');
            }
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
