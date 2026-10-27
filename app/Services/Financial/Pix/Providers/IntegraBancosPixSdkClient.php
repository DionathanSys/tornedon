<?php

namespace App\Services\Financial\Pix\Providers;

use App\Models\BankAccountConnection;
use App\Services\Financial\Banking\Providers\IntegraBancosSdkTransport;
use Illuminate\Validation\ValidationException;
use IntegraBancos\SdkPHP\Pix;

final class IntegraBancosPixSdkClient implements IntegraBancosPixClientInterface
{
    public function __construct(private readonly BankAccountConnection $connection, private readonly array $config = []) {}

    public function generate(array $payload): array
    {
        return app(IntegraBancosSdkTransport::class)->call($this->connection, Pix::class, 'gerar', $payload);
    }

    public function query(array $payload): array
    {
        return app(IntegraBancosSdkTransport::class)->call($this->connection, Pix::class, 'consultarPix', $payload);
    }

    public function assertCancellationSupported(): void
    {
        if (! method_exists(Pix::class, 'cancelarPix')) {
            throw ValidationException::withMessages([
                'charge' => ['O SDK IntegraBancos instalado não oferece cancelamento PIX. É necessário obter do provedor o contrato/API de cancelamento antes de habilitar esta operação.'],
            ]);
        }
    }

    public function cancel(array $payload): array
    {
        $this->assertCancellationSupported();

        return app(IntegraBancosSdkTransport::class)->call($this->connection, Pix::class, 'cancelarPix', $payload);
    }
}
