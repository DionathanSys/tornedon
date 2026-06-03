<?php

namespace Tests\Feature\Services\FiscalDocument;

use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\NfeStatus;
use App\Enum\FiscalDocument\Status;
use App\Models\Company;
use App\Models\FiscalDocument;
use App\Models\Partner;
use App\Services\FiscalDocument\Actions\ConsultNfeAction;
use App\Services\FiscalDocument\Actions\ConsultNfseAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class FiscalDocumentRejectionFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_consult_nfe_marks_rejected_document_as_pending_for_retry(): void
    {
        $document = $this->createFiscalDocument(DocumentModel::NFE);
        $document->update([
            'status' => Status::CONFIRMED->value,
            'nfe_status' => NfeStatus::IN_PROCESSING->value,
            'document_key' => 'NFE-KEY-001',
        ]);

        $sdkMock = Mockery::mock('overload:CloudDfe\SdkPHP\Nfe');
        $sdkMock->shouldReceive('consulta')
            ->once()
            ->andReturn((object) [
                'sucesso' => false,
                'codigo' => 4001,
                'mensagem' => 'NF-e rejeitada pela SEFAZ',
                'erros' => [],
            ]);

        $action = app(ConsultNfeAction::class);
        $result = $action->execute($document->fresh());

        $this->assertFalse($result);

        $document->refresh();

        $this->assertSame(NfeStatus::REJECTED, $document->nfe_status);
        $this->assertSame(Status::PENDING, $document->status);
        $this->assertNotEmpty($document->errors_messages);
    }

    public function test_consult_nfse_marks_rejected_document_as_pending_for_retry(): void
    {
        $document = $this->createFiscalDocument(DocumentModel::NFSE);
        \App\Models\NfseSequence::query()->create([
            'company_id' => $document->company_id,
            'serie' => '1',
            'last_number' => 1,
        ]);
        $document->update([
            'status' => Status::CONFIRMED->value,
            'nfse_status' => NfeStatus::IN_PROCESSING->value,
            'document_key' => 'NFSE-KEY-001',
            'rps_number' => '1',
            'rps_series' => '1',
        ]);

        $sdkMock = Mockery::mock('overload:CloudDfe\SdkPHP\Nfse');
        $sdkMock->shouldReceive('consulta')
            ->once()
            ->andReturn((object) [
                'sucesso' => false,
                'codigo' => 4002,
                'mensagem' => 'NFS-e rejeitada pela prefeitura',
                'erros' => [],
            ]);

        $action = app(ConsultNfseAction::class);
        $result = $action->execute($document->fresh());

        $this->assertFalse($result);

        $document->refresh();

        $this->assertSame(NfeStatus::REJECTED, $document->nfse_status);
        $this->assertSame(Status::PENDING, $document->status);
        $this->assertNotEmpty($document->errors_messages);
    }

    public function test_consult_nfse_marks_reconciliation_when_rejected_document_is_not_highest_rps(): void
    {
        $document = $this->createFiscalDocument(DocumentModel::NFSE);
        \App\Models\NfseSequence::query()->create([
            'company_id' => $document->company_id,
            'serie' => '1',
            'last_number' => 2,
        ]);
        $document->update([
            'status' => Status::CONFIRMED->value,
            'nfse_status' => NfeStatus::IN_PROCESSING->value,
            'document_key' => 'NFSE-KEY-002',
            'rps_number' => '1',
            'rps_series' => '1',
        ]);

        $sdkMock = Mockery::mock('overload:CloudDfe\SdkPHP\Nfse');
        $sdkMock->shouldReceive('consulta')
            ->once()
            ->andReturn((object) [
                'sucesso' => false,
                'codigo' => 4002,
                'mensagem' => 'NFS-e rejeitada pela prefeitura',
                'erros' => [],
            ]);

        $action = app(ConsultNfseAction::class);
        $result = $action->execute($document->fresh());

        $this->assertFalse($result);

        $document->refresh();

        $this->assertSame(NfeStatus::RPS_RECONCILIATION_PENDING, $document->nfse_status);
        $this->assertSame(Status::PENDING, $document->status);
        $this->assertNotEmpty($document->errors_messages);
    }

    private function createFiscalDocument(DocumentModel $documentType): FiscalDocument
    {
        $company = Company::create([
            'name' => 'Empresa Fluxo Fiscal',
            'document_number' => fake()->numerify('##############'),
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
        ]);

        $customer = Partner::create([
            'name' => 'Cliente Fluxo Fiscal',
            'document_type' => 'CNPJ',
            'document_number' => fake()->numerify('##############'),
        ]);

        return FiscalDocument::create([
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'status' => Status::PENDING->value,
            'issued_at' => now()->toDateString(),
            'movement_at' => now()->toDateString(),
            'document_type' => $documentType->value,
            'nfe_status' => $documentType === DocumentModel::NFE ? NfeStatus::PENDING->value : null,
            'nfse_status' => $documentType === DocumentModel::NFSE ? NfeStatus::PENDING->value : null,
        ]);
    }
}
