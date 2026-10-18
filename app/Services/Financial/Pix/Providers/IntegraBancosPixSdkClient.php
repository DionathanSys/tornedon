<?php

namespace App\Services\Financial\Pix\Providers;

use App\Models\BankAccountConnection;
use App\Services\Financial\Banking\Providers\IntegraBancosSdkTransport;
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
}
