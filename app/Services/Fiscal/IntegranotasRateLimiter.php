<?php

namespace App\Services\Fiscal;

use Illuminate\Support\Facades\Cache;

class IntegranotasRateLimiter
{
    private const GLOBAL_INTERVAL_SECONDS = 1.05; // demais rotas: 60 chamadas por minuto por token

    private const SOFT_INTERVAL_SECONDS = 12.05; // /soft: 5 chamadas por minuto por token

    private const KEY_INTERVAL_SECONDS = 30.05; // /{chave}: 2 chamadas por minuto por token + chave

    private const STATE_TTL_SECONDS = 600;

    private const LOCK_SECONDS = 900;

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function run(string $token, string $bucket, ?string $key, callable $callback): mixed
    {
        $tokenHash = $this->hash($token !== '' ? $token : 'missing-token');

        return Cache::lock("integranotas:rate-limit:lock:{$tokenHash}", self::LOCK_SECONDS)
            ->block(self::LOCK_SECONDS, function () use ($tokenHash, $bucket, $key, $callback): mixed {
                $this->waitFor("integranotas:rate-limit:token:{$tokenHash}", self::GLOBAL_INTERVAL_SECONDS);

                if ($bucket === 'soft') {
                    $this->waitFor("integranotas:rate-limit:soft:{$tokenHash}", self::SOFT_INTERVAL_SECONDS);
                }

                if ($bucket === 'key' && filled($key)) {
                    $this->waitFor(
                        "integranotas:rate-limit:key:{$tokenHash}:{$this->hash((string) $key)}",
                        self::KEY_INTERVAL_SECONDS,
                    );
                }

                return $callback();
            });
    }

    private function waitFor(string $cacheKey, float $intervalSeconds): void
    {
        $lastCallAt = Cache::get($cacheKey);
        $now = microtime(true);

        if (is_numeric($lastCallAt)) {
            $elapsed = $now - (float) $lastCallAt;

            if ($elapsed < $intervalSeconds) {
                usleep((int) (($intervalSeconds - $elapsed) * 1_000_000));
            }
        }

        Cache::put($cacheKey, microtime(true), self::STATE_TTL_SECONDS);
    }

    private function hash(string $value): string
    {
        return hash('sha256', $value);
    }
}
