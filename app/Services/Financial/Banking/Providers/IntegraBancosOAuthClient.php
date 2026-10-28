<?php

namespace App\Services\Financial\Banking\Providers;

use IntegraBancos\SdkPHP\Auth;

class IntegraBancosOAuthClient
{
    public function request(array $credentials, ?string $refreshToken, bool $production): array
    {
        $required = [
            'client_id' => 'Client ID',
            'client_secret' => 'Client Secret',
        ];

        if (empty($refreshToken)) {
            $required += [
                'username' => 'Usuário OAuth',
                'password' => 'Senha OAuth',
            ];
        }

        $missing = [];

        foreach ($required as $key => $label) {
            if (empty($credentials[$key]) || trim((string) $credentials[$key]) === '') {
                $missing[] = $label;
            }
        }

        if ($missing !== []) {
            throw new \InvalidArgumentException('Preencha os campos OAuth da conexão bancária: '.implode(', ', $missing).'.');
        }

        return json_decode(json_encode(Auth::getAccessToken($credentials, $refreshToken, $production), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }
}
