<?php

namespace Tests\Feature\Services\FiscalDocument\Actions;

use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\NfeStatus;
use App\Enum\FiscalDocument\Status;
use App\Models\Company;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentPayload;
use App\Models\Partner;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Fiscal\NfeConfigService;
use App\Services\FiscalDocument\Actions\CancelNfeAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class CancelNfeActionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_cancels_authorized_nfe_via_sdk(): void
    {
        $user = User::factory()->create();
        $company = Company::query()->create([
            'name' => 'Empresa Cancelamento',
            'document_number' => '12345678000199',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);

        $customer = Partner::query()->create([
            'name' => 'Cliente Cancelamento',
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
            'created_by' => $user->id,
        ]);
        FiscalDocumentPayload::query()->create([
            'company_id' => $company->id,
            'fiscal_document_id' => $document->id,
            'nfe_payload' => ['foo' => 'bar'],
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
        $sdk->shouldReceive('cancela')
            ->once()
            ->with(Mockery::on(function (array $payload): bool {
                return $payload['chave'] === '35260412345678000199550010000000011000000011'
                    && $payload['justificativa'] === 'Justificativa valida para cancelamento';
            }))
            ->andReturn((object) [
                'sucesso' => true,
                'codigo' => 101,
                'mensagem' => 'Homologado o cancelamento da NF-e',
                'protocolo' => '141190000844226',
                'xml' => 'xml-base64',
                'pdf' => 'pdf-base64',
                'xml_cancelado' => 'xml-cancelado-base64',
            ]);

        $action = app(CancelNfeAction::class);

        $this->assertTrue($action->execute($document, 'Justificativa valida para cancelamento'));

        $document->refresh();

        $this->assertSame(NfeStatus::CANCELED, $document->nfe_status);
        $this->assertSame(Status::CANCELLED, $document->status);
        $this->assertSame('141190000844226', $document->nfe_protocolo);
        $this->assertNotNull($document->canceled_at);
        $this->assertSame('xml-base64', data_get($document->nfe_payload, 'xml_base64'));
        $this->assertSame('pdf-base64', data_get($document->nfe_payload, 'pdf_base64'));
        $this->assertSame('xml-cancelado-base64', data_get($document->nfe_payload, 'xml_cancelado_base64'));
    }

    public function test_it_rejects_short_cancellation_justification(): void
    {
        $user = User::factory()->create();
        $company = Company::query()->create([
            'name' => 'Empresa Cancelamento',
            'document_number' => '12345678000199',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);

        $customer = Partner::query()->create([
            'name' => 'Cliente Cancelamento',
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

        $action = app(CancelNfeAction::class);

        $this->assertFalse($action->execute($document, 'Muito curta'));
        $this->assertSame('A justificativa do cancelamento deve ter entre 15 e 255 caracteres.', $action->getMessage());
    }
}
