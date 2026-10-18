<?php

namespace App\Services\Financial\Banking\Providers;

use App\Models\BankAccountConnection;

final class IntegraBancosSdkTransport
{
    public function call(BankAccountConnection $connection, string $class, string $method, array $payload): array
    {
        $credentials = (array) $connection->fresh()->credentials;
        if (empty($credentials['x_api_key']) || strlen((string) ($credentials['secret_key'] ?? '')) !== 32) {
            throw new \InvalidArgumentException('Configure x_api_key e secret_key de exatamente 32 bytes antes de enviar a cobrança.');
        }
        $tokens = app(IntegraBancosTokenManager::class);
        $token = $tokens->token($connection);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $credentials = (array) $connection->fresh()->credentials;
            $client = new $class([
                'access_token' => $token, 'x_api_key' => $credentials['x_api_key'] ?? '',
                'secret_key' => $credentials['secret_key'] ?? '',
                'is_production' => in_array($connection->environment, ['production', 'prod'], true),
            ]);
            $result = json_decode(json_encode($client->{$method}($payload), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            if (IntegraBancosResponse::code($result) !== '23' || $attempt === 1) {
                return $result;
            }
            // Only an explicit unauthenticated response permits one immediate retry.
            $token = $tokens->token($connection, $token);
        }

        return [];
    }
}
