<?php

namespace App\Services\Financial\Banking\Providers;

use App\Models\BankAccountConnection;
use IntegraBancos\SdkPHP\Boleto;

final class IntegraBancosSdkClient implements IntegraBancosClientInterface
{
    public function __construct(private readonly BankAccountConnection $connection, private readonly array $config = []) {}

    public function generate(array $payload): array
    {
        return $this->call('gerarBoleto', $payload);
    }

    public function update(array $payload): array
    {
        return $this->call('alterarBoleto', $payload);
    }

    public function cancel(array $payload): array
    {
        return $this->call('cancelarBoleto', $payload);
    }

    public function query(array $payload): array
    {
        return $this->call('consultarBoleto', $payload);
    }

    public function queryEvent(array $payload): array
    {
        return $this->call('consultarEventoBoleto', $payload);
    }

    private function call(string $method, array $payload): array
    {
        return app(IntegraBancosSdkTransport::class)->call($this->connection, Boleto::class, $method, $payload);
    }
}
