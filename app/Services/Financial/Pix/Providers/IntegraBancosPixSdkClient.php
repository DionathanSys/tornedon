<?php

namespace App\Services\Financial\Pix\Providers;

use App\Models\BankAccountConnection;
use App\Services\Financial\Banking\Providers\IntegraBancosExceptionFormatter;
use Illuminate\Support\Facades\Log;
use IntegraBancos\SdkPHP\Auth;
use IntegraBancos\SdkPHP\Pix;
use RuntimeException;
use Throwable;

final class IntegraBancosPixSdkClient implements IntegraBancosPixClientInterface
{
    public function __construct(
        private readonly BankAccountConnection $connection,
        private readonly array $config = [],
    ) {}

    public function generate(array $payload): array
    {
        return $this->toArray($this->client()->gerar($payload));
    }

    public function query(array $payload): array
    {
        return $this->toArray($this->client()->consultar($payload));
    }

    private function client(): Pix
    {
        $credentials = (array) ($this->connection->credentials ?? []);

        return new Pix([
            'access_token' => $this->resolveAccessToken($credentials),
            'x_api_key' => (string) ($credentials['x_api_key'] ?? ''),
            'secret_key' => (string) ($credentials['secret_key'] ?? data_get($this->connection->settings, 'secret_key', '')),
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
        $authCredentials = [
            'client_id' => $credentials['client_id'] ?? null,
            'client_secret' => $credentials['client_secret'] ?? null,
            'username' => $credentials['username'] ?? $credentials['login'] ?? null,
            'password' => $credentials['password'] ?? null,
        ];

        try {
            $response = Auth::getAccessToken(
                $authCredentials,
                $refreshToken !== '' ? $refreshToken : null,
                $this->isProduction(),
            );
        } catch (Throwable $exception) {
            Log::error('IntegraBancos PIX: falha na autenticação OAuth', [
                'connection_id' => $this->connection->id,
                'environment' => $this->connection->environment,
                'grant_type' => $refreshToken !== '' ? 'refresh_token' : 'password',
                'exception_class' => $exception::class,
                'exception_message' => IntegraBancosExceptionFormatter::message($exception),
            ]);

            throw $exception;
        }

        $tokenData = $this->toArray($response);
        $accessToken = (string) ($tokenData['access_token'] ?? '');

        if ($accessToken === '') {
            throw new RuntimeException(
                'Falha na autenticação OAuth: '.IntegraBancosExceptionFormatter::dataMessage($tokenData)
            );
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
        } catch (Throwable) {
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
