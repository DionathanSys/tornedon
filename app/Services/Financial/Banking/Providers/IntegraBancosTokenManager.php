<?php

namespace App\Services\Financial\Banking\Providers;

use App\Models\BankAccountConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class IntegraBancosTokenManager
{
    public function __construct(private readonly IntegraBancosOAuthClient $client) {}

    public function token(BankAccountConnection $connection, ?string $rejectedToken = null): string
    {
        return Cache::lock('integrabancos-oauth:'.$connection->id, 150)->block(10, function () use ($connection, $rejectedToken): string {
            $connection->refresh();
            $credentials = (array) $connection->credentials;
            $token = (string) ($credentials['access_token'] ?? '');
            $expires = $credentials['access_token_expires_at'] ?? null;
            $valid = $expires === null;
            if ($expires !== null) {
                try {
                    $valid = now()->parse($expires)->isFuture();
                } catch (\Throwable) {
                    $valid = false;
                }
            }
            if ($token !== '' && $valid && $token !== $rejectedToken) {
                return $token;
            }
            $oauth = array_intersect_key($credentials, array_flip(['client_id', 'client_secret', 'username', 'password']));
            $oauth['username'] = $oauth['username'] ?? $credentials['login'] ?? null;
            $refresh = $credentials['refresh_token'] ?? null;
            $invalidRefresh = false;
            try {
                $result = $this->client->request($oauth, $refresh ?: null, in_array($connection->environment, ['production', 'prod'], true));
                if ($refresh && ($result['error'] ?? null) === 'invalid_grant' && filled($oauth['username'] ?? null) && filled($oauth['password'] ?? null)) {
                    $invalidRefresh = true;
                    $result = $this->client->request($oauth, null, in_array($connection->environment, ['production', 'prod'], true));
                }
            } catch (\Throwable $exception) {
                throw new IntegraBancosAuthenticationException(
                    'Não foi possível autenticar a conexão na IntegraBancos. '.IntegraBancosExceptionFormatter::message($exception),
                    0,
                    $exception,
                );
            }
            if (empty($result['access_token'])) {
                throw new IntegraBancosAuthenticationException(
                    'A renovação OAuth não retornou access_token. '.IntegraBancosExceptionFormatter::dataMessage($result),
                );
            }
            DB::transaction(function () use ($connection, $credentials, $result, $invalidRefresh): void {
                $locked = BankAccountConnection::query()->lockForUpdate()->findOrFail($connection->id);
                $current = (array) $locked->credentials;
                foreach (['client_id', 'client_secret', 'username', 'login', 'password', 'refresh_token'] as $field) {
                    if (($current[$field] ?? null) !== ($credentials[$field] ?? null)) {
                        throw new IntegraBancosAuthenticationException('As credenciais mudaram durante a renovação; consulte novamente.');
                    }
                }
                $locked->update(['credentials' => [
                    ...$current, 'access_token' => $result['access_token'],
                    'refresh_token' => $result['refresh_token'] ?? ($invalidRefresh ? null : ($current['refresh_token'] ?? null)),
                    'access_token_expires_at' => now()->addSeconds(max(1, (int) ($result['expires_in'] ?? 900) - 60))->toIso8601String(),
                ]]);
            });

            return $result['access_token'];
        });
    }
}
