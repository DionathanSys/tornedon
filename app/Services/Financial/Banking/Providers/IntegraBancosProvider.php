<?php

namespace App\Services\Financial\Banking\Providers;

use App\Models\BankAccountConnection;
use App\Models\BankSlip;
use App\Services\Financial\Banking\Contracts\BankSlipProviderInterface;
use App\Services\Financial\Banking\DTO\BankSlipProviderResult;
use App\Services\Financial\Banking\DTO\BankSlipWebhookEvent;
use Illuminate\Support\Facades\Log;
use RuntimeException;

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

        return $this->sendProviderRequest($bankSlip, 'cancel', fn (): array => $this->client->cancel($payload));
    }

    public function query(BankSlip $bankSlip): BankSlipProviderResult
    {
        return $this->sendProviderRequest($bankSlip, 'query', fn (): array => $this->client->query([
            'identificacao' => $bankSlip->provider_identification,
        ]));
    }

    public function validateWebhook(BankAccountConnection $connection, array $payload, string $rawBody): bool
    {
        $connection->loadMissing('company');

        $payloadDocument = preg_replace('/\D+/', '', (string) ($payload['cnpj_cpf'] ?? '')) ?: '';
        $companyDocument = preg_replace('/\D+/', '', (string) $connection->company?->document_number) ?: '';
        $signature = trim((string) ($payload['assinatura'] ?? ''));
        $configuredSignature = trim((string) data_get($connection->settings, 'webhook_signature', ''));

        if (
            $payloadDocument === ''
            || $companyDocument === ''
            || ! hash_equals($companyDocument, $payloadDocument)
            || $signature === ''
            || $configuredSignature === ''
        ) {
            return false;
        }

        // The provider documentation does not publish the signature algorithm yet.
        // Plain comparison is supported only for an explicitly configured test value.
        return (bool) config('app.debug') && hash_equals($configuredSignature, $signature);
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
        } catch (\InvalidArgumentException $exception) {
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

        $statusCode = $this->responseStatusCode($payloadResponse);
        $statusMessage = $this->responseValue($payloadResponse, [
            'status.mensagem',
            'status.message',
            'message',
            'mensagem',
        ]);
        $successful = $this->responseIsSuccessful($payloadResponse, $statusCode);

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
            retryable: false,
            httpStatus: $successful ? 200 : 422,
            providerIdentification: $this->responseValue($payloadResponse, [
                'identificacao',
                'identification',
                'data.identificacao',
            ]) ?: $bankSlip->provider_identification,
            providerChargeId: $this->responseValue($payloadResponse, [
                'id',
                'charge_id',
                'cobranca.id',
                'data.id',
            ]),
            pdfUrl: $this->responseValue($payloadResponse, [
                'pdf',
                'pdf_url',
                'url_pdf',
                'data.pdf',
            ]),
            digitableLine: $this->responseValue($payloadResponse, [
                'linha_digitavel',
                'linhaDigitavel',
                'data.linha_digitavel',
            ]),
            barcode: $this->responseValue($payloadResponse, [
                'codigo_barras',
                'barcode',
                'data.codigo_barras',
            ]),
            statusCode: $statusCode,
            statusMessage: $statusMessage,
            payload: $payloadResponse,
            errors: $successful ? [] : [$statusMessage ?: 'Provider recusou a operacao.'],
            message: $successful ? null : ($statusMessage ?: 'Provider recusou a operacao.'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function failureContext(BankSlip $bankSlip, string $operation): array
    {
        return [
            'operation' => $operation,
            'endpoint' => $operation === 'query'
                ? '/charge/{identificacao}'
                : '/charge',
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
        return [
            'codigo_banco' => (string) $bankSlip->connection?->bank?->code,
            'identificacao' => $bankSlip->provider_identification,
            'data_vencimento' => $bankSlip->due_date?->toDateString(),
            'valor' => number_format((float) $bankSlip->amount, 2, '.', ''),
        ];
    }

    private function responseIsSuccessful(array $payload, ?string $statusCode): bool
    {
        $error = data_get($payload, 'error') ?? data_get($payload, 'erro') ?? data_get($payload, 'errors');

        if (filled($error)) {
            return false;
        }

        if ($statusCode === null) {
            return true;
        }

        return in_array($statusCode, ['2', '3', '4', '5', '7'], true);
    }

    private function responseStatusCode(array $payload): ?string
    {
        $status = data_get($payload, 'status.codigo')
            ?? data_get($payload, 'status.code')
            ?? data_get($payload, 'status');

        return is_scalar($status) && filled($status) ? (string) $status : null;
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

    /**
     * @return array<string, mixed>
     */
    private function chargePayload(BankSlip $bankSlip): array
    {
        $installment = $bankSlip->installment;
        $receivable = $installment?->accountReceivable;
        $customer = $receivable?->customer;
        $document = preg_replace('/\D+/', '', (string) $customer?->document_number) ?: '';
        $documentField = strtoupper((string) $customer?->document_type) === 'CNPJ' ? 'cnpj' : 'cpf';

        if ($document === '' || ! $customer?->name) {
            throw new RuntimeException('Cliente sem documento ou nome para emissao do boleto.');
        }

        return [
            'identificacao' => $bankSlip->provider_identification,
            'codigo_banco' => (string) $bankSlip->connection?->bank?->code,
            'pagamento' => [
                'valor' => number_format((float) $bankSlip->amount, 2, '.', ''),
                'data_vencimento' => $bankSlip->due_date?->toDateString(),
            ],
            'pagador' => [
                $documentField => $document,
                'nome' => $customer->name,
            ],
        ];
    }
}
