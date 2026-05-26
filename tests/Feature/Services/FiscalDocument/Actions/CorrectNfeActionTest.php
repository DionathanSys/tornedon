<?php

namespace Tests\Feature\Services\FiscalDocument\Actions;

use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\NfeStatus;
use App\Enum\FiscalDocument\Status;
use App\Models\Company;
use App\Models\FiscalDocument;
use App\Models\Partner;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Fiscal\NfeConfigService;
use App\Services\FiscalDocument\Actions\CorrectNfeAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class CorrectNfeActionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_issues_correction_letter_for_authorized_nfe_via_sdk(): void
    {
        $user = User::factory()->create();
        $company = Company::query()->create([
            'name' => 'Empresa Correcao',
            'document_number' => '12345678000199',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);

        $customer = Partner::query()->create([
            'name' => 'Cliente Correcao',
            'document_type' => 'CNPJ',
            'document_number' => '22345678000188',
            'created_by' => $user->id,
        ]);

        $document = FiscalDocument::query()->create([
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'status' => Status::CONFIRMED->value,
            'document_type' => DocumentModel::NFE->value,
            'issued_at' => now()->toDateString(),
            'movement_at' => now()->toDateString(),
            'document_key' => '35260412345678000199550010000000011000000011',
            'document_number' => '1',
            'document_series' => '1',
            'nfe_status' => NfeStatus::AUTHORIZED->value,
            'nfe_payload' => ['foo' => 'bar'],
            'created_by' => $user->id,
        ]);

        $config = Mockery::mock(NfeConfigService::class);
        $config->shouldReceive('buildSdkParams')->andReturn([
            'token' => 'fake-token',
            'ambiente' => 2,
            'options' => [],
        ]);
        $this->app->instance(NfeConfigService::class, $config);

        $audit = Mockery::mock(AuditRecorder::class);
        $audit->shouldReceive('snapshot')->andReturn([], []);
        $audit->shouldReceive('recordModelEvent')->once();
        $this->app->instance(AuditRecorder::class, $audit);

        $sdk = Mockery::mock('overload:CloudDfe\SdkPHP\Nfe');
        $sdk->shouldReceive('correcao')
            ->once()
            ->with(Mockery::on(function (array $payload): bool {
                return $payload['chave'] === '35260412345678000199550010000000011000000011'
                    && $payload['justificativa'] === 'Correcao valida para a nota fiscal emitida'
                    && ! array_key_exists('sequencial', $payload);
            }))
            ->andReturn((object) [
                'sucesso' => true,
                'codigo' => 135,
                'mensagem' => 'Evento registrado e vinculado a NF-e',
                'protocolo' => '141190000844206',
                'data_hora_evento' => '2019-09-16 17:09:02',
                'xml_carta_correcao' => 'xml-cce-base64',
                'pdf_carta_correcao' => 'pdf-cce-base64',
                'numero_carta_correcao' => 2,
            ]);

        $action = app(CorrectNfeAction::class);

        $this->assertTrue($action->execute($document, 'Correcao valida para a nota fiscal emitida'));

        $document->refresh();

        $this->assertSame('141190000844206', $document->nfe_protocolo);
        $this->assertSame('Correcao valida para a nota fiscal emitida', data_get($document->nfe_payload, 'correcoes.0.justificativa'));
        $this->assertSame(2, data_get($document->nfe_payload, 'correcoes.0.sequencial'));
        $this->assertSame('xml-cce-base64', data_get($document->nfe_payload, 'correcoes.0.xml_base64'));
        $this->assertSame('pdf-cce-base64', data_get($document->nfe_payload, 'correcoes.0.pdf_base64'));
    }

    public function test_it_rejects_short_correction_justification(): void
    {
        $user = User::factory()->create();
        $company = Company::query()->create([
            'name' => 'Empresa Correcao',
            'document_number' => '12345678000199',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);

        $customer = Partner::query()->create([
            'name' => 'Cliente Correcao',
            'document_type' => 'CNPJ',
            'document_number' => '22345678000188',
            'created_by' => $user->id,
        ]);

        $document = FiscalDocument::query()->create([
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'status' => Status::CONFIRMED->value,
            'document_type' => DocumentModel::NFE->value,
            'issued_at' => now()->toDateString(),
            'movement_at' => now()->toDateString(),
            'document_key' => '35260412345678000199550010000000011000000011',
            'nfe_status' => NfeStatus::AUTHORIZED->value,
            'created_by' => $user->id,
        ]);

        $action = app(CorrectNfeAction::class);

        $this->assertFalse($action->execute($document, 'Muito curta'));
        $this->assertSame('A justificativa da carta de correção deve ter entre 15 e 1000 caracteres.', $action->getMessage());
    }
}
