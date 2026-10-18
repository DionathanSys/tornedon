<?php

namespace App\Services\Financial\Banking;

use App\Models\BankingEvent;
use App\Models\BankSlip;
use App\Models\PixCharge;
use App\Services\Audit\AuditRecorder;
use App\Services\Financial\Banking\DTO\BankSlipProviderResult;
use App\Services\Financial\Banking\Providers\IntegraBancosResponse;
use App\Services\Financial\Pix\DTO\PixProviderResult;
use Illuminate\Support\Facades\DB;

final class BankingEventService
{
    public function observe(BankSlip|PixCharge $charge, BankSlipProviderResult|PixProviderResult $result, string $operation, string $source = 'query'): BankingEvent
    {
        $payload = [
            'response' => $result->payload, 'successful' => $result->successful, 'retryable' => $result->retryable,
            'response_code' => $result->responseCode ?? IntegraBancosResponse::code($result->payload),
            'charge_status' => $result->statusCode, 'message' => $result->message,
            'status_message' => $result->statusMessage, 'provider_charge_id' => $result->providerChargeId,
            'operation_id' => $result instanceof BankSlipProviderResult ? $result->operationId : null,
        ];
        $event = $this->record($charge, $payload, $operation, $source);
        $this->process($event);

        return $event->fresh();
    }

    public function record(BankSlip|PixCharge $charge, array $payload, string $operation, string $source): BankingEvent
    {
        if (in_array($source, ['request', 'intent'], true)) {
            $payload['request_attempt_id'] = $charge->fresh()->operation_attempt_id;
        }
        $identity = [$charge::class, $charge->id, $operation, $source, $this->canonical($payload)];

        $event = BankingEvent::query()->firstOrCreate([
            'bank_account_connection_id' => $charge->bank_account_connection_id,
            'event_key' => hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR)),
        ], [
            'company_id' => $charge->company_id,
            $charge instanceof BankSlip ? 'bank_slip_id' : 'pix_charge_id' => $charge->id,
            'source' => $source, 'operation' => $operation, 'payload' => $payload,
            'received_at' => now(), 'occurred_at' => IntegraBancosResponse::date(data_get($payload, 'response.data_hora')),
        ]);
        if (! $event->wasRecentlyCreated) {
            $event->increment('deliveries');
            $event->update(['last_received_at' => now()]);
            if ($source === 'query') {
                $charge->newQuery()->whereKey($charge->id)->update(['last_synchronized_at' => now()]);
            }
        }

        return $event;
    }

    public function process(BankingEvent $event): void
    {
        try {
            DB::transaction(function () use ($event): void {
                $event = BankingEvent::query()->lockForUpdate()->findOrFail($event->id);
                if (! in_array($event->status, ['pending', 'failed'], true)) {
                    return;
                }
                $charge = $event->bank_slip_id
                    ? BankSlip::query()->lockForUpdate()->find($event->bank_slip_id)
                    : PixCharge::query()->lockForUpdate()->find($event->pix_charge_id);
                if (! $charge || (int) $charge->company_id !== (int) $event->company_id
                    || (int) $charge->bank_account_connection_id !== (int) $event->bank_account_connection_id) {
                    $this->review($event, null, 'Evento sem cobrança válida da mesma empresa e conexão.');

                    return;
                }
                $event->increment('attempts');
                $body = (array) data_get($event->payload, 'response', []);
                $state = data_get($event->payload, 'charge_status');
                $code = data_get($event->payload, 'response_code');
                $before = app(AuditRecorder::class)->snapshot($charge);
                $documents = $charge instanceof BankSlip
                    ? ['pdf_url' => ['pdf', 'dados.pdf', 'data.pdf'], 'digitable_line' => ['linha_digitavel', 'dados.linha_digitavel'], 'barcode' => ['codigo_barras', 'dados.codigo_barras']]
                    : ['qr_code' => ['qrcode', 'qr_code', 'dados.qrcode', 'dados.qr_code', 'encodedImage', 'dados.encodedImage'], 'pix_copy_paste' => ['pix_copia_cola', 'dados.pix_copia_cola', 'payload', 'dados.payload']];
                $updates = [
                    'last_synchronized_at' => now(), 'provider_payload' => $body,
                    'provider_response_code' => $code, 'provider_status_code' => $state,
                    'provider_status_message' => data_get($body, 'dados.status.mensagem') ?? data_get($body, 'status.mensagem') ?? data_get($body, 'mensagem'),
                ];
                if ($message = data_get($event->payload, 'status_message')) {
                    $updates['provider_status_message'] = $message;
                }
                if ($providerId = data_get($event->payload, 'provider_charge_id')) {
                    $updates['provider_charge_id'] = $providerId;
                }
                foreach ($documents as $field => $paths) {
                    foreach ($paths as $path) {
                        $value = data_get($body, $path);
                        if (is_string($value) && $value !== '') {
                            $updates[$field] = $value;
                            break;
                        }
                    }
                }
                $charge->update($updates);
                if ($event->source === 'request' && in_array($event->operation, ['register', 'cancel', 'update'], true)) {
                    $charge->update(['operation_kind' => $event->operation]);
                }
                $terminal = in_array($charge->status->value, ['paid', 'payment_returned', 'canceled'], true);
                if (! data_get($event->payload, 'successful', false)) {
                    $message = data_get($event->payload, 'message') ?: data_get($body, 'mensagem') ?: 'Operação rejeitada pelo provedor.';
                    if ($code === '4' || ($code === '3' && in_array($event->operation, ['register', 'update', 'cancel'], true))) {
                        if (! $terminal) {
                            $charge->update(['status' => $event->operation === 'register' ? 'processing' : $charge->status, 'operation_status' => 'pending', 'last_error' => null]);
                        }
                    } elseif (data_get($event->payload, 'retryable', false) && in_array($event->operation, ['register', 'update', 'cancel'], true)) {
                        if (! $terminal) {
                            $charge->update(['status' => $event->operation === 'register' ? 'uncertain' : $charge->status, 'operation_status' => 'uncertain', 'last_error' => $message]);
                        }
                    } else {
                        $charge->update(['last_error' => $message, 'operation_status' => 'failed']);
                        if ($event->operation === 'register' && ! $terminal) {
                            $charge->update(['status' => 'registration_failed']);
                        }
                    }
                } elseif ($event->operation === 'event_query') {
                    // Status 5 belongs to the requested operation, not to the charge.
                    $charge->update(['operation_status' => $code === '4' ? 'pending' : ($state === '5' ? 'confirmed' : 'pending'), 'last_error' => null]);
                } elseif ($state === '3') {
                    app(BankSettlementService::class)->settle($charge, $event);
                } elseif ($state === '7') {
                    $this->review($event, $charge, 'O banco informou pagamento devolvido. Identifique o recebimento e registre sua contrapartida; não exclua a baixa original.');
                    $charge->update(['operation_status' => 'returned_requires_review']);
                } elseif ($state === '9') {
                    $this->review($event, $charge, 'Rejeição bancária: '.json_encode($body['detalhes'] ?? [], JSON_UNESCAPED_UNICODE));
                } elseif ($state === '4') {
                    if (in_array($charge->status->value, ['paid', 'partially_paid', 'payment_returned'], true)) {
                        $this->review($event, $charge, 'Cancelamento informado para uma cobrança com efeito financeiro.');
                    } else {
                        $charge->update(['status' => 'canceled', 'canceled_at' => $charge->canceled_at ?? now(), 'operation_status' => 'confirmed', 'last_error' => null]);
                    }
                } elseif ($state === '2') {
                    if ($charge instanceof BankSlip && $charge->status->value === 'update_pending' && $charge->pending_update && $event->operation === 'query') {
                        $value = data_get($body, 'dados.valor');
                        $date = IntegraBancosResponse::date(data_get($body, 'dados.data_vencimento'));
                        if (is_numeric($value) && (int) round((float) $value * 100) === (int) round((float) $charge->pending_update['valor'] * 100)
                            && $date?->toDateString() === $charge->pending_update['data_vencimento']) {
                            $charge->update(['amount' => $value, 'due_date' => $date, 'status' => 'registered', 'pending_update' => null, 'operation_status' => 'confirmed', 'last_error' => null]);
                        }
                    }
                    $financialReview = $charge->status->value === 'needs_review' && BankingEvent::query()
                        ->where($charge instanceof BankSlip ? 'bank_slip_id' : 'pix_charge_id', $charge->id)
                        ->where('status', 'needs_review')->get(['payload'])
                        ->contains(fn ($pending) => in_array((string) data_get($pending->payload, 'charge_status'), ['3', '7'], true));
                    if (! $terminal && ! $financialReview && ! in_array($charge->status->value, ['partially_paid', 'cancel_pending', 'update_pending'], true)) {
                        $charge->update(['status' => 'registered', 'registered_at' => $charge->registered_at ?? now(), 'operation_status' => 'confirmed', 'last_error' => null]);
                    }
                } elseif ($state === '5' && $event->source === 'webhook') {
                    $charge->update(['operation_status' => 'confirmed']);
                } elseif ($state === '6') {
                    // Vencido does not mean cancelled: banks may still accept payment.
                    $charge->update(['last_error' => null]);
                } elseif ($code === '4' || in_array($state, ['0', '1', '5'], true)) {
                    if (! $terminal) {
                        $pending = ['operation_status' => 'pending', 'last_error' => null];
                        if (in_array($charge->status->value, ['pending_registration', 'registration_failed', 'uncertain', 'processing'], true)) {
                            $pending['status'] = 'processing';
                        }
                        $charge->update($pending);
                    }
                } elseif (data_get($event->payload, 'successful') && $state === null) {
                    $this->review($event, $charge, 'Resposta sem estado de cobrança reconhecido.');
                } else {
                    $this->review($event, $charge, 'Estado bancário não reconhecido.');
                }
                if ($operationId = data_get($event->payload, 'operation_id')) {
                    $charge->update(['provider_operation_id' => $operationId]);
                }
                if ($event->fresh()->status === 'pending' || $event->fresh()->status === 'failed') {
                    $event->update(['status' => 'processed', 'processed_at' => now(), 'error' => null]);
                }
                app(AuditRecorder::class)->recordModelEvent(
                    $charge, 'banking.observation_applied', 'Retorno bancário processado', $before,
                    app(AuditRecorder::class)->snapshot($charge->fresh()), null, null, ['banking_event_id' => $event->id, 'source' => $event->source, 'operation' => $event->operation],
                );
            });
        } catch (\Throwable $exception) {
            BankingEvent::query()->whereKey($event->id)->update(['status' => 'failed', 'error' => $exception->getMessage(), 'attempts' => DB::raw('attempts + 1')]);
            throw $exception;
        }
    }

    public function review(BankingEvent $event, BankSlip|PixCharge|null $charge, string $reason): void
    {
        $event->update(['status' => 'needs_review', 'error' => $reason]);
        if ($charge) {
            // Preserve financially terminal states; the pending event holds the discrepancy.
            $charge->update([
                'status' => in_array($charge->status->value, ['paid', 'payment_returned', 'canceled'], true) ? $charge->status : 'needs_review',
                'last_error' => $reason,
            ]);
        }
    }

    private function canonical(array $payload): array
    {
        unset($payload['assinatura']);
        ksort($payload);
        foreach ($payload as &$value) {
            if (is_array($value)) {
                $value = $this->canonical($value);
            }
        }

        return $payload;
    }
}
