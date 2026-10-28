<?php

namespace App\Services\Financial\Banking\Providers;

use App\Models\BankAccountConnection;
use App\Models\BankSlip;
use App\Services\Financial\Banking\Contracts\BankSlipProviderInterface;
use App\Services\Financial\Banking\DTO\BankSlipProviderResult;
use App\Services\Financial\Banking\DTO\BankSlipWebhookEvent;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

final class IntegraBancosProvider implements BankSlipProviderInterface
{
    private readonly IntegraBancosClientInterface $client;

    public function __construct(
        private readonly BankAccountConnection $connection,
        private readonly array $config = [],
        ?IntegraBancosClientInterface $client = null,
    ) {
        $this->client = $client ?? new IntegraBancosSdkClient($connection, $config);
    }

    public function register(BankSlip $bankSlip): BankSlipProviderResult
    {
        $bankSlip->loadMissing('connection.bank', 'installment.accountReceivable.customer');

        return $this->sendProviderRequest($bankSlip, 'register', fn (): array => $this->client->generate(
            $this->chargePayload($bankSlip)
        ));
    }

    public function update(BankSlip $bankSlip): BankSlipProviderResult
    {
        $bankSlip->loadMissing('connection.bank', 'installment.accountReceivable.customer');

        return $this->sendProviderRequest($bankSlip, 'update', fn (): array => $this->client->update(
            $this->updatePayload($bankSlip)
        ));
    }

    public function cancel(BankSlip $bankSlip): BankSlipProviderResult
    {
        $payload = [
            'codigo_banco' => (string) $bankSlip->connection?->bank?->code,
            'identificacao' => $bankSlip->provider_identification,
            'motivo' => 'Cancelamento solicitado pela empresa.',
        ];

        return $this->sendProviderRequest($bankSlip, 'cancel', function () use ($bankSlip, $payload): array {
            app(IntegraBancosPayloadBuilder::class)->assertOperation($bankSlip->connection, 'cancel');

            return $this->client->cancel($payload);
        });
    }

    public function query(BankSlip $bankSlip): BankSlipProviderResult
    {
        $bankSlip->loadMissing('connection.bank');

        return $this->sendProviderRequest($bankSlip, 'query', fn (): array => $this->client->query([
            'codigo_banco' => (string) $bankSlip->connection?->bank?->code,
            'identificacao' => $bankSlip->provider_identification,
        ]));
    }

    public function validateWebhook(BankAccountConnection $connection, array $payload, string $rawBody): bool
    {
        $connection->loadMissing('company');

        $payloadDocument = preg_replace('/\D+/', '', (string) ($payload['cnpj_cpf'] ?? '')) ?: '';
        $companyDocument = preg_replace('/\D+/', '', (string) $connection->company?->document_number) ?: '';
        $signature = trim((string) ($payload['assinatura'] ?? ''));
        $credentials = (array) ($connection->credentials ?? []);
        $encryptionKey = (string) ($credentials['secret_key'] ?? data_get($connection->settings, 'secret_key', ''));

        $reason = match (true) {
            $payloadDocument === '' => 'payload_document_missing',
            $companyDocument === '' => 'company_document_missing',
            ! hash_equals($companyDocument, $payloadDocument) => 'document_mismatch',
            $signature === '' => 'signature_missing',
            $encryptionKey === '' => 'secret_key_missing',
            default => null,
        };
        if ($reason !== null) {
            return $this->rejectWebhook($connection, $payload, $reason);
        }

        $decoded = base64_decode($signature, true);

        if ($decoded === false || strlen($decoded) <= 48) {
            return $this->rejectWebhook($connection, $payload, 'signature_invalid_encoding');
        }

        $iv = substr($decoded, 0, 16);
        $encryptedTimestamp = substr($decoded, 48);
        $providedMac = substr($decoded, 16, 32);
        $expectedMac = hash_hmac('sha256', $encryptedTimestamp, $encryptionKey, true);

        if (! hash_equals($providedMac, $expectedMac)) {
            return $this->rejectWebhook($connection, $payload, 'signature_mac_mismatch');
        }

        $timestamp = openssl_decrypt(
            $encryptedTimestamp,
            'aes-128-cbc',
            $encryptionKey,
            OPENSSL_RAW_DATA,
            $iv,
        );

        if (! is_string($timestamp) || ! ctype_digit($timestamp)) {
            return $this->rejectWebhook($connection, $payload, 'signature_timestamp_invalid');
        }

        $age = time() - (int) $timestamp;
        if (abs($age) > 300) {
            return $this->rejectWebhook($connection, $payload, 'signature_timestamp_outside_window', [
                'timestamp_age_seconds' => $age,
                'tolerance_seconds' => 300,
            ]);
        }

        return true;
    }

    private function rejectWebhook(BankAccountConnection $connection, array $payload, string $reason, array $context = []): bool
    {
        Log::warning('IntegraBancos: validacao do webhook rejeitada', [
            'connection_id' => $connection->id,
            'company_id' => $connection->company_id,
            'identification' => $payload['identificacao'] ?? null,
            'event_type' => $payload['evento'] ?? null,
            'reason' => $reason,
            ...$context,
        ]);

        return false;
    }

    public function queryEvent(BankSlip $bankSlip, string $eventId): BankSlipProviderResult
    {
        return $this->sendProviderRequest($bankSlip, 'event_query', fn (): array => $this->client->queryEvent([
            'codigo_banco' => (string) $bankSlip->connection->bank->code,
            'identificacao' => $bankSlip->provider_identification, 'evento_id' => $eventId,
        ]));
    }

    public function normalizeWebhook(array $payload): BankSlipWebhookEvent
    {
        $providerEventId = $payload['evento_id'] ?? data_get($payload, 'event_id');

        if (! filled($providerEventId)) {
            $fingerprintPayload = $payload;
            unset($fingerprintPayload['assinatura']);
            $fingerprintPayload = $this->sortPayload($fingerprintPayload);
            $providerEventId = hash(
                'sha256',
                (string) json_encode($fingerprintPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }

        $amount = $payload['valor'] ?? null;

        return new BankSlipWebhookEvent(
            providerEventId: $providerEventId,
            eventType: filled($payload['evento'] ?? null) ? (string) $payload['evento'] : null,
            identification: filled($payload['identificacao'] ?? null) ? (string) $payload['identificacao'] : null,
            statusCode: filled(data_get($payload, 'status.codigo')) ? (string) data_get($payload, 'status.codigo') : null,
            statusMessage: filled(data_get($payload, 'status.mensagem')) ? (string) data_get($payload, 'status.mensagem') : null,
            amount: is_numeric($amount) ? round((float) $amount, 2) : null,
            payload: $payload,
        );
    }

    private function sortPayload(mixed $payload): mixed
    {
        if (! is_array($payload)) {
            return $payload;
        }

        if (array_is_list($payload)) {
            return array_map(fn (mixed $value): mixed => $this->sortPayload($value), $payload);
        }

        ksort($payload);

        foreach ($payload as $key => $value) {
            $payload[$key] = $this->sortPayload($value);
        }

        return $payload;
    }

    private function sendProviderRequest(BankSlip $bankSlip, string $operation, \Closure $request): BankSlipProviderResult
    {
        try {
            $payloadResponse = $request();
        } catch (\InvalidArgumentException|ValidationException|IntegraBancosAuthenticationException $exception) {
            $message = IntegraBancosExceptionFormatter::message($exception);

            Log::warning('IntegraBancos: requisicao rejeitada antes do envio', [
                ...$this->failureContext($bankSlip, $operation),
                'exception_class' => $exception::class,
                'exception_code' => $exception->getCode(),
                'exception_message' => $message,
                'exception_details' => IntegraBancosExceptionFormatter::details($exception),
            ]);

            return new BankSlipProviderResult(
                successful: false,
                retryable: false,
                httpStatus: 0,
                errors: [$message],
                message: $message,
                responseCode: 'LOCAL_VALIDATION',
            );
        } catch (\Throwable $exception) {
            $message = IntegraBancosExceptionFormatter::message($exception);

            Log::error('IntegraBancos: falha na comunicacao com o provider', [
                ...$this->failureContext($bankSlip, $operation),
                'exception_class' => $exception::class,
                'exception_code' => $exception->getCode(),
                'exception_message' => $message,
                'exception_details' => IntegraBancosExceptionFormatter::details($exception),
            ]);

            return new BankSlipProviderResult(
                successful: false,
                retryable: true,
                httpStatus: 0,
                errors: [$message],
                message: $message,
            );
        }

        $statusCode = IntegraBancosResponse::status($payloadResponse);
        $statusMessage = $this->responseValue($payloadResponse, [
            'dados.status.mensagem',
            'dados.status.message',
            'status.mensagem',
            'status.message',
            'data.status.mensagem',
            'data.status.message',
            'message',
            'mensagem',
            'error_description',
            'detail',
        ]);
        $errorDetails = $this->responseErrors($payloadResponse);

        if (filled($errorDetails)) {
            $statusMessage = trim(implode(' | ', array_filter([$statusMessage, $errorDetails])));
        }
        $successful = IntegraBancosResponse::accepted($payloadResponse);

        if (! $successful) {
            Log::warning('IntegraBancos: provider recusou a operacao', [
                ...$this->failureContext($bankSlip, $operation),
                'status_code' => $statusCode,
                'status_message' => $statusMessage,
                'response' => IntegraBancosExceptionFormatter::sanitizeData($payloadResponse),
            ]);
        }

        return new BankSlipProviderResult(
            successful: $successful,
            retryable: ! $successful && IntegraBancosResponse::code($payloadResponse) === '17',
            httpStatus: 0,
            providerIdentification: $this->responseValue($payloadResponse, [
                'identificacao',
                'identification',
                'dados.identificacao',
                'dados.identification',
                'data.identificacao',
                'data.identification',
            ]) ?: $bankSlip->provider_identification,
            providerChargeId: $this->responseValue($payloadResponse, [
                'id',
                'charge_id',
                'cobranca.id',
                'dados.id',
                'dados.charge_id',
                'dados.cobranca.id',
                'data.id',
                'data.charge_id',
                'data.cobranca.id',
            ]),
            pdfUrl: $this->responseValue($payloadResponse, [
                'pdf',
                'pdf_url',
                'url_pdf',
                'dados.pdf',
                'dados.pdf_url',
                'dados.url_pdf',
                'data.pdf',
                'data.pdf_url',
                'data.url_pdf',
            ]),
            digitableLine: $this->responseValue($payloadResponse, [
                'linha_digitavel',
                'linhaDigitavel',
                'dados.linha_digitavel',
                'dados.linhaDigitavel',
                'data.linha_digitavel',
                'data.linhaDigitavel',
            ]),
            barcode: $this->responseValue($payloadResponse, [
                'codigo_barras',
                'barcode',
                'dados.codigo_barras',
                'dados.barcode',
                'data.codigo_barras',
                'data.barcode',
            ]),
            statusCode: $statusCode,
            statusMessage: $statusMessage,
            payload: $payloadResponse,
            errors: $successful ? [] : [$statusMessage ?: 'Provider recusou a operacao.'],
            message: $successful ? null : ($statusMessage ?: 'Provider recusou a operacao.'),
            responseCode: IntegraBancosResponse::code($payloadResponse),
            operationId: isset($payloadResponse['evento_id']) ? (string) $payloadResponse['evento_id'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function failureContext(BankSlip $bankSlip, string $operation): array
    {
        return [
            'operation' => $operation,
            'endpoint' => match ($operation) {
                'query' => '/cobranca/{codigo_banco}/{identificacao}',
                'event_query' => '/cobranca/{codigo_banco}/{identificacao}/{evento_id}',
                default => '/cobranca',
            },
            'connection_id' => $this->connection->id,
            'bank_slip_id' => $bankSlip->id,
            'provider_identification' => $bankSlip->provider_identification,
            'environment' => $this->connection->environment,
            'is_production' => $this->isProduction(),
        ];
    }

    private function isProduction(): bool
    {
        return in_array(strtolower((string) $this->connection->environment), ['production', 'prod'], true);
    }

    /**
     * @return array<string, mixed>
     */
    private function updatePayload(BankSlip $bankSlip): array
    {
        app(IntegraBancosPayloadBuilder::class)->assertOperation($bankSlip->connection, 'update');

        return [
            'codigo_banco' => (string) $bankSlip->connection?->bank?->code,
            'identificacao' => $bankSlip->provider_identification,
            'data_vencimento' => data_get($bankSlip->pending_update, 'data_vencimento') ?? $bankSlip->due_date?->toDateString(),
            'valor' => data_get($bankSlip->pending_update, 'valor') ?? number_format((float) $bankSlip->amount, 2, '.', ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $paths
     */
    private function responseValue(array $payload, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = data_get($payload, $path);

            if (is_scalar($value) && filled($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    private function responseErrors(array $payload): ?string
    {
        $errors = data_get($payload, 'erros') ?? data_get($payload, 'errors');

        if (! is_array($errors) || $errors === []) {
            return null;
        }

        return collect($errors)
            ->map(function (mixed $error): ?string {
                if (is_scalar($error)) {
                    return (string) $error;
                }

                if (! is_array($error)) {
                    return null;
                }

                $field = filled($error['campo'] ?? null) ? (string) $error['campo'].': ' : '';
                $message = (string) ($error['erro'] ?? $error['mensagem'] ?? $error['message'] ?? '');

                return filled($message) ? $field.$message : null;
            })
            ->filter()
            ->implode('; ') ?: null;
    }

    /**
     * @return array<string, mixed>
     */
    private function chargePayload(BankSlip $bankSlip): array
    {
        $payload = app(IntegraBancosPayloadBuilder::class)->build($bankSlip);
        $bankSlip->update(['request_snapshot' => $payload]);

        return $payload;
    }
}
