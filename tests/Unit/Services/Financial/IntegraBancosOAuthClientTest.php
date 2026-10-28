<?php

namespace Tests\Unit\Services\Financial;

use App\Services\Financial\Banking\Providers\IntegraBancosOAuthClient;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class IntegraBancosOAuthClientTest extends TestCase
{
    public function test_identifies_each_missing_password_grant_field_without_exposing_values(): void
    {
        $credentials = [
            'client_id' => 'private-id',
            'client_secret' => 'private-secret',
            'username' => 'private-user',
            'password' => 'private-password',
        ];

        foreach (['client_id' => 'Client ID', 'client_secret' => 'Client Secret', 'username' => 'Usuário OAuth', 'password' => 'Senha OAuth'] as $key => $label) {
            $incomplete = $credentials;
            $incomplete[$key] = '   ';

            try {
                (new IntegraBancosOAuthClient)->request($incomplete, null, false);
                $this->fail('Credenciais incompletas deveriam impedir a requisição.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('Preencha os campos OAuth da conexão bancária: '.$label.'.', $exception->getMessage());

                foreach ($credentials as $value) {
                    $this->assertStringNotContainsString($value, $exception->getMessage());
                }
            }
        }
    }

    public function test_refresh_grant_only_reports_missing_client_credentials(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Preencha os campos OAuth da conexão bancária: Client ID, Client Secret.');

        (new IntegraBancosOAuthClient)->request([], 'private-refresh-token', false);
    }
}
