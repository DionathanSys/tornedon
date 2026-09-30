<?php

namespace Tests\Unit\Services\Financial;

use App\Services\Financial\Banking\Providers\IntegraBancosExceptionFormatter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class IntegraBancosExceptionFormatterTest extends TestCase
{
    public function test_formats_sdk_error_with_transport_details_without_exposing_credentials(): void
    {
        $exception = new RuntimeException((string) json_encode([
            'message' => 'Erro na Requisição cURL',
            'error' => 'Could not resolve host',
            'http_code' => 0,
            'config' => [
                'client_secret' => 'client-secret',
                'password' => 'password',
                'access_token' => 'access-token',
            ],
        ]));

        $message = IntegraBancosExceptionFormatter::message($exception);
        $details = json_encode(IntegraBancosExceptionFormatter::details($exception));

        $this->assertStringContainsString('Could not resolve host', $message);
        $this->assertStringContainsString('http_code: 0', $message);
        $this->assertStringNotContainsString('client-secret', $message.$details);
        $this->assertStringNotContainsString('access-token', $message.$details);
        $this->assertStringNotContainsString('"password":"password"', $message.$details);
    }

    public function test_formats_oauth_response_without_exposing_tokens(): void
    {
        $response = [
            'error' => 'invalid_grant',
            'error_description' => 'Usuário ou senha inválidos.',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
        ];

        $message = IntegraBancosExceptionFormatter::dataMessage($response);
        $details = json_encode(IntegraBancosExceptionFormatter::sanitizeData($response));

        $this->assertStringContainsString('invalid_grant', $message);
        $this->assertStringContainsString('Usuário ou senha inválidos.', $message);
        $this->assertStringNotContainsString('access-token', $message.$details);
        $this->assertStringNotContainsString('refresh-token', $message.$details);
    }
}
