<?php

namespace Tests\Feature\Services\FiscalDocument\Actions;

use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\NfeStatus;
use App\Enum\FiscalDocument\Status;
use App\Models\Company;
use App\Models\FiscalDocument;
use App\Models\Partner;
use App\Models\User;
use App\Services\AccountReceivable\AccountReceivableGenerationService;
use App\Services\Audit\AuditRecorder;
use App\Services\Fiscal\IntegranotasRateLimiter;
use App\Services\Fiscal\NfseConfigService;
use App\Services\FiscalDocument\Actions\ConsultNfseAction;
use App\Services\FiscalDocument\Actions\StoreFiscalDocumentAttachmentsAction;
use App\Services\FiscalDocument\Actions\UpsertFiscalDocumentPayloadAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class ConsultNfseActionTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('cancellationResponses')]
    public function test_consultation_synchronizes_cancellation_without_generating_receivables(array $response): void
    {
        $document = $this->createDocument();
        $authorizedAt = $document->authorized_at->copy();
        app(UpsertFiscalDocumentPayloadAction::class)->execute($document, [
            'nfse_payload' => ['original' => 'preserved'],
        ]);
        $this->mockResponse($document, $response + [
            'sucesso' => true,
            'mensagem' => 'Homologado o cancelamento da NFS-e',
            'data_hora_evento' => '2026-10-28 05:19:21',
            'xml' => base64_encode('<NFSe/>'),
            'pdf' => base64_encode('%PDF-test'),
        ], 'fiscal_document.nfse_canceled');
        $this->mock(AccountReceivableGenerationService::class)
            ->shouldNotReceive('generateFromFiscalDocument');
        $this->mock(StoreFiscalDocumentAttachmentsAction::class)->shouldNotReceive('execute');

        $action = new ConsultNfseAction;

        $this->assertTrue($action->execute($document), $action->getMessage() ?? '');
        $this->assertFalse($action->hasError());
        $document->refresh();
        $this->assertSame(NfeStatus::CANCELED, $document->nfse_status);
        $this->assertSame(Status::CANCELLED, $document->status);
        $this->assertSame('2026-10-28 05:19:21', $document->canceled_at->format('Y-m-d H:i:s'));
        $this->assertTrue($authorizedAt->equalTo($document->authorized_at));
        $this->assertSame('17', $document->rps_number);
        $this->assertSame('1', $document->rps_series);
        $this->assertSame('original-protocol', $document->nfse_protocol);
        $this->assertNull($document->errors_messages);
        $this->assertSame('preserved', $document->nfse_payload['original']);
        $this->assertSame(base64_encode('<NFSe/>'), $document->nfse_payload['xml_base64']);
        $this->assertSame(base64_encode('%PDF-test'), $document->nfse_payload['pdf_base64']);
    }

    public static function cancellationResponses(): array
    {
        return [
            'Integra cancellation response' => [['codigo' => 101, 'status' => 'Cancelado']],
            'numeric code without status' => [['codigo' => '101']],
            'normalized cancellation status' => [['codigo' => 100, 'status' => ' Cancelada ']],
        ];
    }

    public function test_authorized_response_still_confirms_document(): void
    {
        $document = $this->createDocument();
        $document->update(['nfse_status' => NfeStatus::IN_PROCESSING->value]);
        $this->mockResponse($document, [
            'sucesso' => true,
            'codigo' => 100,
            'status' => 'Autorizado',
            'numero' => '17',
            'protocolo' => 'new-protocol',
        ], 'fiscal_document.nfse_authorized');
        $this->mock(StoreFiscalDocumentAttachmentsAction::class)
            ->shouldReceive('execute')->once()->andReturnTrue();
        $this->mock(AccountReceivableGenerationService::class)
            ->shouldReceive('generateFromFiscalDocument')->once()->andReturnTrue();

        $action = new ConsultNfseAction;

        $this->assertTrue($action->execute($document), $action->getMessage() ?? '');
        $document->refresh();
        $this->assertSame(NfeStatus::AUTHORIZED, $document->nfse_status);
        $this->assertSame(Status::CONFIRMED, $document->status);
        $this->assertSame('new-protocol', $document->nfse_protocol);
        $this->assertNull($document->canceled_at);
    }

    public function test_rejected_response_still_returns_error(): void
    {
        $document = $this->createDocument();
        $document->update(['nfse_status' => NfeStatus::IN_PROCESSING->value]);
        $this->mockResponse($document, [
            'sucesso' => false,
            'codigo' => 5001,
            'mensagem' => 'Erro de validação',
        ], 'fiscal_document.nfse_rps_reconciliation_pending');
        $this->mock(AccountReceivableGenerationService::class)
            ->shouldNotReceive('generateFromFiscalDocument');

        $action = new ConsultNfseAction;

        $this->assertFalse($action->execute($document));
        $this->assertTrue($action->hasError());
        $this->assertSame('Erro de validação', $action->getMessage());
        $document->refresh();
        $this->assertSame(Status::PENDING, $document->status);
        $this->assertSame(NfeStatus::RPS_RECONCILIATION_PENDING, $document->nfse_status);
        $this->assertNull($document->canceled_at);
        $this->assertNotEmpty($document->errors_messages);
    }

    private function mockResponse(FiscalDocument $document, array $response, string $auditEvent): void
    {
        $this->mock(NfseConfigService::class, function ($mock): void {
            $mock->shouldReceive('buildSdkParams')->once()->andReturn([]);
            $mock->shouldReceive('resolveToken')->once()->andReturn('fake-token');
        });
        $sdk = Mockery::mock('overload:CloudDfe\SdkPHP\Nfse');
        $sdk->shouldReceive('consulta')->once()
            ->with(['chave' => $document->document_key])
            ->andReturn((object) $response);
        $this->mock(IntegranotasRateLimiter::class)
            ->shouldReceive('run')->once()
            ->andReturnUsing(fn ($token, $bucket, $key, $callback) => $callback());
        $this->mock(AuditRecorder::class, function ($mock) use ($auditEvent): void {
            $mock->shouldReceive('snapshot')->twice()->andReturn([]);
            $mock->shouldReceive('recordModelEvent')->once()
                ->withArgs(fn ($model, $event, ...$args) => $event === $auditEvent);
        });
    }

    private function createDocument(): FiscalDocument
    {
        $user = User::factory()->create();
        $company = Company::factory()->create(['created_by' => $user->id]);
        $customer = Partner::query()->create([
            'name' => 'Cliente NFS-e',
            'document_type' => 'CNPJ',
            'document_number' => '22345678000188',
            'created_by' => $user->id,
        ]);

        return FiscalDocument::query()->create([
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'status' => Status::PENDING->value,
            'document_type' => DocumentModel::NFSE->value,
            'document_key' => str_repeat('1', 44),
            'nfse_status' => NfeStatus::CANCELED->value,
            'nfse_protocol' => 'original-protocol',
            'authorized_at' => now()->subWeek(),
            'rps_number' => '17',
            'rps_series' => '1',
            'issued_at' => now()->subWeek()->toDateString(),
            'movement_at' => now()->subWeek()->toDateString(),
            'created_by' => $user->id,
        ]);
    }
}
