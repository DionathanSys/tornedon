<?php

namespace Tests\Feature\Services\Cnpj;

use App\Services\Cnpj\CnpjConsultationService;
use App\Services\Cnpj\CnpjProviderSettingsRepository;
use App\Services\Cnpj\Contracts\CnpjApiProviderInterface;
use App\Services\Cnpj\DTO\CnpjProviderResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class CnpjConsultationServiceTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_CNPJ = '12345678000195';

    protected function setUp(): void
    {
        parent::setUp();

        FakeRetryFailureProvider::reset();
        FakeStopFailureProvider::reset();
        FakeSuccessProvider::reset();

        config()->set('cnpj.providers', ['retry_failure', 'success']);
        config()->set('cnpj.provider_classes', [
            'retry_failure' => FakeRetryFailureProvider::class,
            'stop_failure' => FakeStopFailureProvider::class,
            'success' => FakeSuccessProvider::class,
        ]);
        config()->set('cnpj.provider_labels', [
            'retry_failure' => 'Retry Failure',
            'stop_failure' => 'Stop Failure',
            'success' => 'Success',
        ]);
        config()->set('cnpj.provider_settings', [
            'retry_failure' => ['base_url' => 'https://retry.test', 'timeout' => 5, 'headers' => [], 'rate_limit' => ['max_attempts' => 5, 'decay_seconds' => 60]],
            'stop_failure' => ['base_url' => 'https://stop.test', 'timeout' => 5, 'headers' => [], 'rate_limit' => ['max_attempts' => 5, 'decay_seconds' => 60]],
            'success' => ['base_url' => 'https://success.test', 'timeout' => 5, 'headers' => [], 'rate_limit' => ['max_attempts' => 5, 'decay_seconds' => 60]],
        ]);
    }

    public function test_rejects_invalid_cnpj_before_consulting_providers(): void
    {
        $service = app(CnpjConsultationService::class);

        $result = $service->consult('11111111111111');

        $this->assertNull($result);
        $this->assertSame('CNPJ inválido. Informe 14 digitos numéricos.', $service->getMessage());
        $this->assertSame([], FakeRetryFailureProvider::$calls);
        $this->assertSame([], FakeSuccessProvider::$calls);
    }

    public function test_falls_back_to_next_enabled_provider(): void
    {
        Log::spy();

        app(CnpjProviderSettingsRepository::class)->save([
            ['name' => 'retry_failure', 'enabled' => true, 'base_url' => 'https://retry.test', 'timeout' => 5, 'headers' => [], 'rate_limit' => ['max_attempts' => 5, 'decay_seconds' => 60]],
            ['name' => 'success', 'enabled' => true, 'base_url' => 'https://success.test', 'timeout' => 5, 'headers' => [], 'rate_limit' => ['max_attempts' => 5, 'decay_seconds' => 60]],
            ['name' => 'stop_failure', 'enabled' => false, 'base_url' => 'https://stop.test', 'timeout' => 5, 'headers' => [], 'rate_limit' => ['max_attempts' => 5, 'decay_seconds' => 60]],
        ]);

        $service = app(CnpjConsultationService::class);
        $result = $service->consult('12.345.678/0001-95');

        $this->assertNotNull($result);
        $this->assertSame('Empresa Teste', $result->companyName);
        $this->assertSame([self::VALID_CNPJ], FakeRetryFailureProvider::$calls);
        $this->assertSame([self::VALID_CNPJ], FakeSuccessProvider::$calls);
        $this->assertSame('Consulta CNPJ realizada com sucesso (success)', $service->getMessage());
        $this->assertSame('success', data_get($service->getData(), 'provider'));

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context): bool {
            return $message === 'Consulta CNPJ realizada com sucesso (success)'
                && ($context['log_identifier'] ?? null) === 'cnpj_consultation'
                && ($context['event'] ?? null) === 'success'
                && ($context['provider'] ?? null) === 'success'
                && ($context['cnpj'] ?? null) === self::VALID_CNPJ;
        })->once();
    }

    public function test_stops_fallback_when_provider_marks_failure_as_non_retryable(): void
    {
        app(CnpjProviderSettingsRepository::class)->save([
            ['name' => 'stop_failure', 'enabled' => true, 'base_url' => 'https://stop.test', 'timeout' => 5, 'headers' => [], 'rate_limit' => ['max_attempts' => 5, 'decay_seconds' => 60]],
            ['name' => 'success', 'enabled' => true, 'base_url' => 'https://success.test', 'timeout' => 5, 'headers' => [], 'rate_limit' => ['max_attempts' => 5, 'decay_seconds' => 60]],
            ['name' => 'retry_failure', 'enabled' => false, 'base_url' => 'https://retry.test', 'timeout' => 5, 'headers' => [], 'rate_limit' => ['max_attempts' => 5, 'decay_seconds' => 60]],
        ]);

        $service = app(CnpjConsultationService::class);
        $result = $service->consult(self::VALID_CNPJ);

        $this->assertNull($result);
        $this->assertSame([self::VALID_CNPJ], FakeStopFailureProvider::$calls);
        $this->assertSame([], FakeSuccessProvider::$calls);
        $this->assertSame('Falha sem fallback.', $service->getMessage());
    }

    public function test_returns_cached_value_before_provider_resolution(): void
    {
        app(CnpjProviderSettingsRepository::class)->save([
            ['name' => 'success', 'enabled' => true, 'base_url' => 'https://success.test', 'timeout' => 5, 'headers' => [], 'rate_limit' => ['max_attempts' => 5, 'decay_seconds' => 60]],
            ['name' => 'retry_failure', 'enabled' => false, 'base_url' => 'https://retry.test', 'timeout' => 5, 'headers' => [], 'rate_limit' => ['max_attempts' => 5, 'decay_seconds' => 60]],
            ['name' => 'stop_failure', 'enabled' => false, 'base_url' => 'https://stop.test', 'timeout' => 5, 'headers' => [], 'rate_limit' => ['max_attempts' => 5, 'decay_seconds' => 60]],
        ]);

        $service = app(CnpjConsultationService::class);

        $first = $service->consult(self::VALID_CNPJ);

        $this->assertNotNull($first);
        $this->assertCount(1, FakeSuccessProvider::$calls);

        app(CnpjProviderSettingsRepository::class)->save([
            ['name' => 'success', 'enabled' => false, 'base_url' => 'https://success.test', 'timeout' => 5, 'headers' => [], 'rate_limit' => ['max_attempts' => 5, 'decay_seconds' => 60]],
            ['name' => 'retry_failure', 'enabled' => false, 'base_url' => 'https://retry.test', 'timeout' => 5, 'headers' => [], 'rate_limit' => ['max_attempts' => 5, 'decay_seconds' => 60]],
            ['name' => 'stop_failure', 'enabled' => false, 'base_url' => 'https://stop.test', 'timeout' => 5, 'headers' => [], 'rate_limit' => ['max_attempts' => 5, 'decay_seconds' => 60]],
        ]);

        $second = $service->consult(self::VALID_CNPJ);

        $this->assertNotNull($second);
        $this->assertSame('Empresa Teste', $second->companyName);
        $this->assertCount(1, FakeSuccessProvider::$calls);
        $this->assertSame('Consulta CNPJ realizada com sucesso (cache)', $service->getMessage());
    }
}

class FakeRetryFailureProvider implements CnpjApiProviderInterface
{
    public static array $calls = [];

    public function __construct(private readonly array $config = []) {}

    public function name(): string
    {
        return (string) ($this->config['name'] ?? 'retry_failure');
    }

    public function consult(string $cnpj): CnpjProviderResult
    {
        self::$calls[] = $cnpj;

        return CnpjProviderResult::failure('Falha com fallback.', status: 503, shouldRetryNextProvider: true);
    }

    public static function reset(): void
    {
        self::$calls = [];
    }
}

class FakeStopFailureProvider implements CnpjApiProviderInterface
{
    public static array $calls = [];

    public function __construct(private readonly array $config = []) {}

    public function name(): string
    {
        return (string) ($this->config['name'] ?? 'stop_failure');
    }

    public function consult(string $cnpj): CnpjProviderResult
    {
        self::$calls[] = $cnpj;

        return CnpjProviderResult::failure('Falha sem fallback.', status: 422, shouldRetryNextProvider: false);
    }

    public static function reset(): void
    {
        self::$calls = [];
    }
}

class FakeSuccessProvider implements CnpjApiProviderInterface
{
    public static array $calls = [];

    public function __construct(private readonly array $config = []) {}

    public function name(): string
    {
        return (string) ($this->config['name'] ?? 'success');
    }

    public function consult(string $cnpj): CnpjProviderResult
    {
        self::$calls[] = $cnpj;

        return CnpjProviderResult::success([
            'taxId' => $cnpj,
            'company' => [
                'name' => 'Empresa Teste',
                'nature' => ['text' => 'LTDA'],
                'equity' => 1000.0,
                'simples' => ['optant' => true],
                'simei' => ['optant' => false],
            ],
            'alias' => 'Fantasia Teste',
            'founded' => '2020-01-01',
            'head' => true,
            'status' => ['text' => 'Ativa'],
            'statusDate' => '2020-01-01',
            'address' => [
                'street' => 'Rua Teste',
                'number' => '123',
                'district' => 'Centro',
                'city' => 'Sao Paulo',
                'state' => 'SP',
                'zip' => '01001000',
                'details' => null,
                'municipality' => 3550308,
            ],
            'mainActivity' => ['id' => 6201501, 'text' => 'Desenvolvimento'],
            'sideActivities' => [],
            'registrations' => [
                [
                    'number' => '123456789',
                    'state' => 'SP',
                    'enabled' => true,
                    'status' => ['text' => 'ATIVA'],
                    'type' => ['text' => 'Estadual'],
                ],
            ],
            'phones' => [['area' => '11', 'number' => '999999999']],
            'emails' => [['address' => 'contato@example.com']],
        ]);
    }

    public static function reset(): void
    {
        self::$calls = [];
    }
}
