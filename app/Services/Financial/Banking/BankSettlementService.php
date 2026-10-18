<?php

namespace App\Services\Financial\Banking;

use App\Models\BankingEvent;
use App\Models\BankSlip;
use App\Models\PixCharge;
use App\Services\AccountReceivable\AccountReceivableService;
use App\Services\Financial\Banking\Providers\IntegraBancosResponse;
use App\Support\Financial\BankReceiptMatcher;
use App\Support\Financial\InstallmentPaymentGuard;

final class BankSettlementService
{
    /** Caller locks the observation and charge in the same transaction. */
    public function settle(BankSlip|PixCharge $charge, BankingEvent $event): void
    {
        $events = app(BankingEventService::class);
        if (! $charge->installment || (int) $charge->company_id !== (int) $charge->installment->company_id
            || (int) $charge->connection?->company_id !== (int) $charge->company_id) {
            $events->review($event, $charge, 'Liquidação sem parcela/conexão válida da mesma empresa.');

            return;
        }
        $installment = InstallmentPaymentGuard::lock($charge->installment);
        $body = (array) data_get($event->payload, 'response', []);
        $field = $charge instanceof BankSlip ? 'bank_slip_id' : 'pix_charge_id';
        $accountId = (int) ($charge->financial_account_id ?? $charge->connection->financial_account_id);
        $isWebhook = $event->source === 'webhook';
        $amount = $isWebhook ? ($body['valor'] ?? null) : (data_get($body, 'dados.valor') ?? data_get($body, 'data.valor') ?? $body['valor'] ?? null);
        $dateValue = $isWebhook ? ($body['data_hora'] ?? null) : IntegraBancosResponse::paymentDate($body);
        $date = IntegraBancosResponse::date($dateValue);
        if (! is_numeric($amount) || (float) $amount <= 0 || ! $date) {
            $events->review($event, $charge, 'Pagamento informado sem valor ou data bancária válida; não foi presumida a data de processamento.');

            return;
        }
        $amount = round((float) $amount, 2);
        $event->update(['payload' => [
            ...$event->payload,
            'financial_date_source' => $isWebhook ? 'provider_event_time' : 'provider_payment_date',
            'financial_date_value' => $date->toIso8601String(),
        ]]);
        if ($installment->status->value === 'cancelled' || $installment->accountReceivable->status->value === 'cancelled'
            || in_array($charge->status->value, ['canceled', 'payment_returned'], true)) {
            $events->review($event, $charge, 'Pagamento recebido após cancelamento/devolução; exige tratamento explícito.');

            return;
        }
        $linked = $installment->payments()->where('company_id', $charge->company_id)->where($field, $charge->id)->get();
        $represented = $linked->whereNotNull('bank_confirmed_at')->sum(fn ($payment) => (float) $payment->amount - (float) $payment->returned_amount);
        if (! $isWebhook && $represented > 0) {
            if ((int) round($represented * 100) === (int) round($amount * 100) && $this->fullyCovered($charge)) {
                $charge->update(['status' => 'paid', 'paid_at' => $date, 'operation_status' => 'confirmed', 'last_error' => null]);
            } else {
                $events->review($event, $charge, 'Consulta não distingue pagamentos incrementais do total da cobrança. Não foi inferida uma nova baixa.');
            }

            return;
        }
        $existing = BankReceiptMatcher::dueDatePayment($installment, $field, $charge->id, $amount, $accountId);
        if ($existing) {
            $existing->update(array_filter([
                'bank_confirmed_at' => now(), 'banking_event_id' => $event->id,
                'bank_slip_event_id' => data_get($event->payload, 'legacy_event_id'),
            ], fn ($value) => $value !== null));
            $charge->update(['status' => 'paid', 'paid_at' => $date, 'operation_status' => 'confirmed', 'last_error' => null]);

            return;
        }
        $mode = data_get($charge->connection->settings, 'webhook_amount_mode', 'unknown');
        $mode = in_array($mode, ['incremental', 'cumulative'], true) ? $mode : 'unknown';
        if ($isWebhook) {
            $event->update(['payload' => [...$event->payload, 'amount_semantics' => $mode]]);
            $adjusted = filled(data_get($charge->request_snapshot, 'pagamento.mora')) || filled(data_get($charge->request_snapshot, 'pagamento.multa')) || filled(data_get($charge->request_snapshot, 'pagamento.desconto'));
            if (($mode === 'unknown' && ((int) round($amount * 100) !== (int) round((float) $charge->amount * 100) || $represented > 0))
                || ($adjusted && (int) round($amount * 100) !== (int) round((float) $charge->amount * 100))) {
                $events->review($event, $charge, 'Pagamento parcial/adicional ou com encargos sem semântica/composição homologada. Revise o retorno antes de baixar.');

                return;
            }
            if ($mode === 'cumulative') {
                $difference = (int) round($amount * 100) - (int) round($represented * 100);
                if ($difference === 0) {
                    $fullyCovered = $this->fullyCovered($charge);
                    $charge->update(['status' => $fullyCovered ? 'paid' : 'partially_paid',
                        'paid_at' => $fullyCovered ? ($charge->paid_at ?? $date) : null,
                        'operation_status' => 'confirmed', 'last_error' => null]);

                    return;
                }
                if ($difference < 0) {
                    $events->review($event, $charge, 'Total acumulado inferior ao já representado. Verifique evento atrasado ou devolução.');

                    return;
                }
                $amount = $difference / 100;
            }
        }
        if (! $isWebhook && (filled(data_get($charge->request_snapshot, 'pagamento.mora'))
            || filled(data_get($charge->request_snapshot, 'pagamento.multa')) || filled(data_get($charge->request_snapshot, 'pagamento.desconto')))) {
            $events->review($event, $charge, 'Consulta contém valor nominal e a cobrança possui encargos/desconto; confirme a composição recebida.');

            return;
        }
        if ((int) round($amount * 100) > (int) round((float) $installment->balance_amount * 100)
            || ($charge instanceof PixCharge && (int) round($amount * 100) !== (int) round((float) $charge->amount * 100))) {
            $events->review($event, $charge, 'Valor bancário diverge do saldo/cobrança. Verifique encargos, descontos ou baixas anteriores.');

            return;
        }
        $service = app(AccountReceivableService::class);
        $payment = $service->registerInstallmentPayment($installment, $amount, $date->toDateString(), [
            'financial_account_id' => $accountId, $field => $charge->id,
            'banking_event_id' => $event->id,
            'bank_slip_event_id' => data_get($event->payload, 'legacy_event_id'),
            'description' => 'Recebimento bancário '.$charge->provider_identification,
        ]);
        if (! $payment || $service->hasError()) {
            throw new \RuntimeException($service->getMessageUser());
        }
        $payment->update(['bank_confirmed_at' => now()]);
        $fullyCovered = $this->fullyCovered($charge);
        $charge->update([
            'status' => $fullyCovered ? 'paid' : 'partially_paid',
            'paid_at' => $fullyCovered ? $date : null, 'operation_status' => 'confirmed', 'last_error' => null,
        ]);
    }

    public function fullyCovered(BankSlip|PixCharge $charge): bool
    {
        $field = $charge instanceof BankSlip ? 'bank_slip_id' : 'pix_charge_id';
        $principal = $charge->installment->payments()->where('company_id', $charge->company_id)->where($field, $charge->id)->whereNotNull('bank_confirmed_at')->get()
            ->sum(fn ($payment) => (float) $payment->returned_amount > 0 ? 0 : (float) $payment->amount - (float) $payment->interest_amount - (float) $payment->fine_amount + (float) $payment->discount_amount);

        return (int) round($principal * 100) >= (int) round((float) $charge->amount * 100);
    }
}
