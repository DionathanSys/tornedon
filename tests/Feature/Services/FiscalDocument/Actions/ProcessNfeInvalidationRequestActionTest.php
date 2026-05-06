<?php

namespace Tests\Feature\Services\FiscalDocument\Actions;

use App\Models\Company;
use App\Models\NfeInvalidationRequest;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Fiscal\NfeConfigService;
use App\Services\FiscalDocument\Actions\ProcessNfeInvalidationRequestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ProcessNfeInvalidationRequestActionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_marks_request_as_completed_when_sdk_accepts_invalidation(): void
    {
        $user = User::factory()->create();
        $company = Company::query()->create([
            'name' => 'Empresa Inutilizacao',
            'document_number' => '12345678000199',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);

        $request = NfeInvalidationRequest::query()->create([
            'company_id' => $company->id,
            'serie' => '1',
            'number_start' => 10,
            'number_end' => 10,
            'justification' => 'Teste de inutilizacao',
            'status' => 'pending',
            'requested_by' => $user->id,
        ]);

        $config = Mockery::mock(NfeConfigService::class);
        $config->shouldReceive('buildSdkParams')->andReturn([
            'token' => 'fake-token',
            'ambiente' => 2,
            'options' => [],
        ]);
        $this->app->instance(NfeConfigService::class, $config);

        $sdk = Mockery::mock('overload:CloudDfe\SdkPHP\Nfe');
        $sdk->shouldReceive('inutiliza')
            ->once()
            ->with(Mockery::on(function (array $payload): bool {
                return $payload['numero_inicial'] === '10'
                    && $payload['numero_final'] === '10'
                    && $payload['serie'] === '1'
                    && $payload['justificativa'] === 'Teste de inutilizacao';
            }))
            ->andReturn((object) [
                'sucesso' => true,
                'mensagem' => 'Inutilização realizada com sucesso',
                'protocolo' => '135250000000001',
            ]);

        $service = app(ProcessNfeInvalidationRequestAction::class);

        $this->assertTrue($service->execute($request, $user->id));

        $request->refresh();

        $this->assertSame('completed', $request->status);
        $this->assertSame($user->id, $request->processed_by);
        $this->assertNotNull($request->processed_at);
        $this->assertSame('135250000000001', data_get($request->response_payload, 'protocolo'));
    }
}
