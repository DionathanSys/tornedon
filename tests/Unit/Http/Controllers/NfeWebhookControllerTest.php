<?php

namespace Tests\Unit\Http\Controllers;

use App\Http\Controllers\NfeWebhookController;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

class NfeWebhookControllerTest extends TestCase
{
    #[DataProvider('authorizedPayloadProvider')]
    public function test_resolver_status_payload_identifica_autorizado_com_campos_alternativos(array $payload): void
    {
        $controller = app(NfeWebhookController::class);

        $method = new ReflectionMethod($controller, 'resolverStatusPayload');
        $method->setAccessible(true);

        $status = $method->invoke($controller, $payload);

        $this->assertSame('autorizado', $status);
    }

    public static function authorizedPayloadProvider(): array
    {
        return [
            'status explicito' => [['status' => 'autorizado']],
            'sucesso true' => [['sucesso' => true]],
            'codigo 100' => [['codigo' => 100]],
            'mensagem autorizada' => [['mensagem' => 'Autorizado o uso da NFS-e.']],
        ];
    }

    public function test_resolver_status_payload_mantem_cancelado(): void
    {
        $controller = app(NfeWebhookController::class);

        $method = new ReflectionMethod($controller, 'resolverStatusPayload');
        $method->setAccessible(true);

        $status = $method->invoke($controller, ['status' => 'cancelado']);

        $this->assertSame('cancelado', $status);
    }
}
