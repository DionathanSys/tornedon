<?php

namespace App\Services\Financial\Pix\Providers;

use App\Models\BankAccountConnection;
use App\Models\PixCharge;
use App\Services\Financial\Banking\Providers\IntegraBancosAuthenticationException;
use App\Services\Financial\Banking\Providers\IntegraBancosExceptionFormatter;
use App\Services\Financial\Banking\Providers\IntegraBancosPayloadBuilder;
use App\Services\Financial\Banking\Providers\IntegraBancosResponse;
use App\Services\Financial\Pix\Contracts\PixProviderInterface;
use App\Services\Financial\Pix\DTO\PixProviderResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

final class IntegraBancosPixProvider implements PixProviderInterface
{
    private readonly IntegraBancosPixClientInterface $client;

    public function __construct(
        private readonly BankAccountConnection $connection,
        private readonly array $config = [],
        ?IntegraBancosPixClientInterface $client = null,
    ) {
        $this->client = $client ?? new IntegraBancosPixSdkClient($connection, $config);
    }

    public function register(PixCharge $charge): PixProviderResult
    {
        $charge->loadMissing('connection.bank', 'installment.accountReceivable.customer');

        return $this->sendProviderRequest($charge, 'register', fn (): array => $this->client->generate(
            $this->chargePayload($charge)
        ));
    }

    public function query(PixCharge $charge): PixProviderResult
    {
        $charge->loadMissing('connection.bank');

        return $this->sendProviderRequest($charge, 'query', fn (): array => $this->client->query([
            'codigo_banco' => (string) $charge->connection?->bank?->code,
            'identificacao' => $charge->provider_identification,
        ]));
    }

    public function supports(BankAccountConnection $connection): bool
    {
        $connection->loadMissing('billingProvider');

        return (bool) data_get($connection->billingProvider?->capabilities, 'pix', false);
    }

    public function assertCancellationSupported(): void
    {
        $this->client->assertCancellationSupported();
    }

    public function cancel(PixCharge $charge): PixProviderResult
    {
        $charge->loadMissing('connection.bank');

        return $this->sendProviderRequest($charge, 'cancel', fn (): array => $this->client->cancel([
            'codigo_banco' => (string) $charge->connection?->bank?->code,
            'identificacao' => $charge->provider_identification,
            'motivo' => 'Cancelamento solicitado pela empresa.',
        ]));
    }

    private function sendProviderRequest(PixCharge $charge, string $operation, \Closure $request): PixProviderResult
    {
        try {
            $payload = $request();
        } catch (\InvalidArgumentException|ValidationException|IntegraBancosAuthenticationException $exception) {
            $message = IntegraBancosExceptionFormatter::message($exception);

            Log::warning('IntegraBancos PIX: requisição rejeitada antes do envio', [
                'operation' => $operation,
                'connection_id' => $this->connection->id,
                'pix_charge_id' => $charge->id,
                'message' => $message,
            ]);

            return new PixProviderResult(false, false, 0, errors: [$message], message: $message, responseCode: 'LOCAL_VALIDATION');
        } catch (\Throwable $exception) {
            $message = IntegraBancosExceptionFormatter::message($exception);

            Log::error('IntegraBancos PIX: falha na comunicação com o provider', [
                'operation' => $operation,
                'connection_id' => $this->connection->id,
                'pix_charge_id' => $charge->id,
                'message' => $message,
            ]);

            return new PixProviderResult(false, true, 0, errors: [$message], message: $message);
        }

        $statusCode = IntegraBancosResponse::status($payload);
        $statusMessage = $this->responseValue($payload, [
            'mensagem', 'message', 'status.mensagem', 'status.message',
            'dados.status.mensagem', 'dados.status.message', 'data.status.mensagem',
            'data.status.message', 'error_description', 'detail',
        ]);
        $errors = $this->responseErrors($payload);

        if (filled($errors)) {
            $statusMessage = trim(implode(' | ', array_filter([$statusMessage, $errors])));
        }

        $successful = IntegraBancosResponse::accepted($payload);
        $identification = $this->responseValue($payload, [
            'identificacao', 'identification', 'dados.identificacao', 'data.identificacao',
        ]) ?: $charge->provider_identification;

        return new PixProviderResult(
            successful: $successful,
            retryable: ! $successful && IntegraBancosResponse::code($payload) === '17',
            httpStatus: 0,
            providerIdentification: $identification,
            providerChargeId: $this->responseValue($payload, [
                'id', 'charge_id', 'cobranca.id', 'dados.id', 'dados.charge_id', 'data.id',
            ]),
            qrCode: $this->responseValue($payload, [
                'qrcode', 'qr_code', 'qrCode', 'dados.qrcode', 'dados.qr_code',
                'data.qrcode', 'data.qr_code', 'encodedImage', 'encoded_image',
                'dados.encodedImage', 'dados.encoded_image',
                'data.encodedImage', 'data.encoded_image',
            ]),
            pixCopyPaste: $this->responseValue($payload, [
                'pix_copia_cola', 'pix_copy_paste', 'pixCopiaCola',
                'payload', 'dados.pix_copia_cola', 'dados.pix_copy_paste',
                'dados.payload', 'data.pix_copia_cola', 'data.pix_copy_paste',
                'data.payload',
            ]),
            statusCode: $statusCode,
            statusMessage: $statusMessage,
            amount: $this->responseAmount($payload),
            paid: IntegraBancosResponse::status($payload) === '3',
            payload: $payload,
            errors: $successful ? [] : [$statusMessage ?: 'Provider recusou a operação.'],
            message: $successful ? null : ($statusMessage ?: 'Provider recusou a operação.'),
            responseCode: IntegraBancosResponse::code($payload),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function chargePayload(PixCharge $charge): array
    {
        $payload = app(IntegraBancosPayloadBuilder::class)->build($charge);
        $charge->update(['request_snapshot' => $payload]);

        return $payload;
    }

    private function responseAmount(array $payload): ?float
    {
        $amount = data_get($payload, 'valor')
            ?? data_get($payload, 'pagamento.valor')
            ?? data_get($payload, 'dados.valor')
            ?? data_get($payload, 'dados.pagamento.valor')
            ?? data_get($payload, 'data.valor')
            ?? data_get($payload, 'data.pagamento.valor');

        return is_numeric($amount) ? round((float) $amount, 2) : null;
    }

    /**
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

        return collect($errors)->map(function (mixed $error): ?string {
            if (is_scalar($error)) {
                return (string) $error;
            }

            if (! is_array($error)) {
                return null;
            }

            return (string) ($error['erro'] ?? $error['mensagem'] ?? $error['message'] ?? '');
        })->filter()->implode('; ') ?: null;
    }
}
