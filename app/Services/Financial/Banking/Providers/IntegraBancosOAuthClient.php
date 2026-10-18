<?php

namespace App\Services\Financial\Banking\Providers;

use IntegraBancos\SdkPHP\Auth;

class IntegraBancosOAuthClient
{
    public function request(array $credentials, ?string $refreshToken, bool $production): array
    {
        return json_decode(json_encode(Auth::getAccessToken($credentials, $refreshToken, $production), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }
}
