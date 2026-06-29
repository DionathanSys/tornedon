<?php

namespace Tests\Feature\Services\Cnpj;

use App\Services\Cnpj\CnpjProviderSettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CnpjProviderSettingsRepositoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cnpj.providers', ['brasil_api', 'open_cnpja']);
        config()->set('cnpj.provider_classes', [
            'brasil_api' => FakeRepositoryProvider::class,
            'open_cnpja' => FakeRepositoryProvider::class,
            'cnpj_ws' => FakeRepositoryProvider::class,
            'receitaws' => FakeRepositoryProvider::class,
        ]);
        config()->set('cnpj.provider_labels', [
            'brasil_api' => 'BrasilAPI',
            'open_cnpja' => 'OpenCnpja',
            'cnpj_ws' => 'CNPJ.ws',
            'receitaws' => 'ReceitaWS',
        ]);
        config()->set('cnpj.provider_settings', [
            'brasil_api' => ['base_url' => 'https://brasil.test', 'timeout' => 15, 'headers' => [], 'rate_limit' => ['max_attempts' => 10, 'decay_seconds' => 60]],
            'open_cnpja' => ['base_url' => 'https://open.test', 'timeout' => 20, 'headers' => [], 'rate_limit' => ['max_attempts' => 5, 'decay_seconds' => 60]],
            'cnpj_ws' => ['base_url' => 'https://cnpjws.test', 'timeout' => 15, 'headers' => [], 'rate_limit' => ['max_attempts' => 7, 'decay_seconds' => 60]],
            'receitaws' => ['base_url' => 'https://receita.test', 'timeout' => 25, 'headers' => [], 'rate_limit' => ['max_attempts' => 3, 'decay_seconds' => 120]],
        ]);
    }

    public function test_returns_defaults_when_no_global_setting_exists(): void
    {
        $providers = app(CnpjProviderSettingsRepository::class)->all();

        $this->assertCount(4, $providers);
        $this->assertSame('brasil_api', $providers[0]['name']);
        $this->assertTrue($providers[0]['enabled']);
        $this->assertSame('open_cnpja', $providers[1]['name']);
        $this->assertTrue($providers[1]['enabled']);
        $this->assertSame('cnpj_ws', $providers[2]['name']);
        $this->assertFalse($providers[2]['enabled']);
        $this->assertSame('receitaws', $providers[3]['name']);
        $this->assertFalse($providers[3]['enabled']);
    }

    public function test_persists_and_normalizes_global_provider_configuration(): void
    {
        $repository = app(CnpjProviderSettingsRepository::class);

        $repository->save([
            [
                'name' => 'receitaws',
                'enabled' => true,
                'base_url' => 'https://receita.custom',
                'timeout' => 30,
                'headers' => ['Authorization' => 'Bearer token', '' => 'ignored'],
                'rate_limit' => ['max_attempts' => 9, 'decay_seconds' => 45],
            ],
            [
                'name' => 'brasil_api',
                'enabled' => false,
                'base_url' => 'https://brasil.custom',
                'timeout' => 18,
                'headers' => [],
                'rate_limit' => ['max_attempts' => 12, 'decay_seconds' => 70],
            ],
        ]);

        $providers = $repository->all();

        $this->assertSame('receitaws', $providers[0]['name']);
        $this->assertTrue($providers[0]['enabled']);
        $this->assertSame('https://receita.custom', $providers[0]['base_url']);
        $this->assertSame(['Authorization' => 'Bearer token'], $providers[0]['headers']);
        $this->assertSame(9, $providers[0]['rate_limit']['max_attempts']);
        $this->assertSame(45, $providers[0]['rate_limit']['decay_seconds']);

        $this->assertSame('brasil_api', $providers[1]['name']);
        $this->assertFalse($providers[1]['enabled']);
        $this->assertSame('open_cnpja', $providers[2]['name']);
        $this->assertTrue($providers[2]['enabled']);
        $this->assertSame('cnpj_ws', $providers[3]['name']);
        $this->assertFalse($providers[3]['enabled']);
    }
}

class FakeRepositoryProvider {}
