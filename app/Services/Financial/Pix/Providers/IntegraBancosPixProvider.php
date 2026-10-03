<?php

namespace App\Services\Financial\Pix\Providers;

use App\Models\BankAccountConnection;
use App\Models\PixCharge;
use App\Services\Financial\Banking\Providers\IntegraBancosExceptionFormatter;
use App\Services\Financial\Pix\Contracts\PixProviderInterface;
use App\Services\Financial\Pix\DTO\PixProviderResult;
use Illuminate\Support\Facades\Log;
use RuntimeException;

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
            'identificacao' => $charge->provider_identification,
        ]));
    }

    public function supports(BankAccountConnection $connection): bool
    {
        $connection->loadMissing('billingProvider');

        return (bool) data_get($connection->billingProvider?->capabilities, 'pix', false);
    }

    private function sendProviderRequest(PixCharge $charge, string $operation, \Closure $request): PixProviderResult
    {
        try {
            $payload = $request();
        } catch (\InvalidArgumentException $exception) {
            $message = IntegraBancosExceptionFormatter::message($exception);

            Log::warning('IntegraBancos PIX: requisição rejeitada antes do envio', [
                'operation' => $operation,
                'connection_id' => $this->connection->id,
                'pix_charge_id' => $charge->id,
                'message' => $message,
            ]);

            return new PixProviderResult(false, false, 0, errors: [$message], message: $message);
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

        $statusCode = $this->responseStatusCode($payload);
        $statusMessage = $this->responseValue($payload, [
            'mensagem', 'message', 'status.mensagem', 'status.message',
            'dados.status.mensagem', 'dados.status.message', 'data.status.mensagem',
            'data.status.message', 'error_description', 'detail',
        ]);
        $errors = $this->responseErrors($payload);

        if (filled($errors)) {
            $statusMessage = trim(implode(' | ', array_filter([$statusMessage, $errors])));
        }

        $successful = $this->responseIsSuccessful($payload, $statusCode);
        $identification = $this->responseValue($payload, [
            'identificacao', 'identification', 'dados.identificacao', 'data.identificacao',
        ]) ?: $charge->provider_identification;

        return new PixProviderResult(
            successful: $successful,
            retryable: false,
            httpStatus: $successful ? 200 : 422,
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
            paid: $this->responseIsPaid($payload),
            payload: $payload,
            errors: $successful ? [] : [$statusMessage ?: 'Provider recusou a operação.'],
            message: $successful ? null : ($statusMessage ?: 'Provider recusou a operação.'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function chargePayload(PixCharge $charge): array
    {
        $customer = $charge->installment?->accountReceivable?->customer;
        $document = preg_replace('/\D+/', '', (string) $customer?->document_number) ?: '';
        $documentField = strtoupper((string) $customer?->document_type) === 'CNPJ' ? 'cnpj' : 'cpf';

        if ($document === '' || ! $customer?->name) {
            throw new RuntimeException('Cliente sem documento ou nome para emissão da cobrança PIX.');
        }

        return [
            'codigo_banco' => (string) $charge->connection?->bank?->code,
            'numero' => (string) $charge->id,
            'identificacao' => $charge->provider_identification,
            'pagamento' => [
                'valor' => number_format((float) $charge->amount, 2, '.', ''),
                'data_vencimento' => $charge->due_date?->toDateString(),
            ],
            'pagador' => [
                'cpf' => $documentField === 'cpf' ? $document : null,
                'cnpj' => $documentField === 'cnpj' ? $document : null,
                'nome' => $customer->name,
            ],
        ];
    }

    private function responseIsSuccessful(array $payload, ?string $statusCode): bool
    {
        $success = $payload['sucesso'] ?? $payload['success'] ?? null;

        if (is_bool($success)) {
            return $success;
        }

        if (is_string($success)) {
            if (in_array(strtolower($success), ['true', '1', 'sim'], true)) {
                return true;
            }

            if (in_array(strtolower($success), ['false', '0', 'nao', 'não'], true)) {
                return false;
            }
        }

        if (filled(data_get($payload, 'error')) || filled(data_get($payload, 'erro')) || filled(data_get($payload, 'errors'))) {
            return false;
        }

        return $statusCode === null || in_array($statusCode, ['2', '3', '4', '5', '7'], true);
    }

    private function responseIsPaid(array $payload): bool
    {
        $status = $this->responseValue($payload, [
            'status.codigo', 'status.code', 'status', 'situacao', 'situacao_cobranca',
            'dados.status.codigo', 'dados.status', 'data.status.codigo', 'data.status',
        ]);

        return in_array(strtolower((string) $status), [
            '3', 'pago', 'paga', 'paid', 'liquidado', 'liquidada', 'settled', 'concluido', 'concluida', 'concluído', 'concluída',
        ], true);
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

    private function responseStatusCode(array $payload): ?string
    {
        $status = data_get($payload, 'status.codigo')
            ?? data_get($payload, 'status.code')
            ?? data_get($payload, 'dados.status.codigo')
            ?? data_get($payload, 'data.status.codigo')
            ?? data_get($payload, 'codigo')
            ?? data_get($payload, 'status_code');

        return is_scalar($status) && filled($status) ? (string) $status : null;
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
