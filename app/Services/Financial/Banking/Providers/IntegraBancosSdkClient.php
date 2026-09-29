<?php

namespace App\Services\Financial\Banking\Providers;

use App\Models\BankAccountConnection;
use IntegraBancos\SdkPHP\Auth;
use IntegraBancos\SdkPHP\Boleto;
use RuntimeException;

final class IntegraBancosSdkClient implements IntegraBancosClientInterface
{
    public function __construct(
        private readonly BankAccountConnection $connection,
        private readonly array $config = [],
    ) {}

    public function generate(array $payload): array
    {
        return $this->toArray($this->client()->gerarBoleto($payload));
    }

    public function update(array $payload): array
    {
        return $this->toArray($this->client()->alterarBoleto($payload));
    }

    public function cancel(array $payload): array
    {
        return $this->toArray($this->client()->cancelarBoleto($payload));
    }

    public function query(array $payload): array
    {
        return $this->toArray($this->client()->consultarBoleto($payload));
    }

    private function client(): Boleto
    {
        $credentials = (array) ($this->connection->credentials ?? []);
        $secretKey = (string) ($credentials['secret_key'] ?? $this->connection->settings['secret_key'] ?? '');
        $apiKey = (string) ($credentials['x_api_key'] ?? '');

        return new Boleto([
            'access_token' => $this->resolveAccessToken($credentials),
            'x_api_key' => $apiKey,
            'secret_key' => $secretKey,
            'is_production' => $this->isProduction(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function resolveAccessToken(array $credentials): string
    {
        $accessToken = (string) ($credentials['access_token'] ?? '');
        $expiresAt = $credentials['access_token_expires_at'] ?? null;

        if ($accessToken !== '' && (! $expiresAt || $this->isFuture($expiresAt))) {
            return $accessToken;
        }

        $refreshToken = (string) ($credentials['refresh_token'] ?? '');
        $response = Auth::getAccessToken(
            [
                'client_id' => $credentials['client_id'] ?? null,
                'client_secret' => $credentials['client_secret'] ?? null,
                'username' => $credentials['username'] ?? $credentials['login'] ?? null,
                'password' => $credentials['password'] ?? null,
            ],
            $refreshToken !== '' ? $refreshToken : null,
            $this->isProduction(),
        );
        $tokenData = $this->toArray($response);
        $accessToken = (string) ($tokenData['access_token'] ?? '');

        if ($accessToken === '') {
            throw new RuntimeException('Resposta OAuth da IntegraBancos sem access_token.');
        }

        $credentials['access_token'] = $accessToken;
        $credentials['refresh_token'] = $tokenData['refresh_token'] ?? $credentials['refresh_token'] ?? null;
        $credentials['access_token_expires_at'] = now()
            ->addSeconds(max(60, (int) ($tokenData['expires_in'] ?? 3600) - 60))
            ->toIso8601String();

        $this->connection->forceFill(['credentials' => $credentials])->save();

        return $accessToken;
    }

    private function isProduction(): bool
    {
        return in_array(strtolower((string) $this->connection->environment), ['production', 'prod'], true);
    }

    private function isFuture(mixed $value): bool
    {
        try {
            return now()->parse((string) $value)->isFuture();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(mixed $response): array
    {
        if (is_array($response)) {
            return $response;
        }

        $encoded = json_encode($response);
        $decoded = $encoded === false ? null : json_decode($encoded, true);

        return is_array($decoded) ? $decoded : [];
    }
}
