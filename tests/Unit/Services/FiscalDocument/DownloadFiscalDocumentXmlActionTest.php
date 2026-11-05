<?php

namespace Tests\Unit\Services\FiscalDocument;

use App\Enum\FiscalDocument\NfeStatus;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentPayload;
use App\Services\Fiscal\IntegranotasRateLimiter;
use App\Services\Fiscal\NfeConfigService;
use App\Services\FiscalDocument\XmlExport\DownloadFiscalDocumentXmlAction;
use Mockery;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

class DownloadFiscalDocumentXmlActionTest extends TestCase
{
    public function test_saved_xml_is_returned_without_calling_the_api_even_without_an_access_key(): void
    {
        $xml = '<nfeProc><NFe/></nfeProc>';
        $limiter = Mockery::mock(IntegranotasRateLimiter::class);
        $limiter->shouldNotReceive('run');
        $action = new DownloadFiscalDocumentXmlAction($limiter);

        foreach (['nfe', 'nfse'] as $type) {
            foreach ([$xml, base64_encode($xml)] as $content) {
                $document = $this->document($type, ['xml_base64' => $content]);
                $document->document_key = null;

                $this->assertSame($xml, $action->execute($document));
                $this->assertTrue($action->isSuccess());
            }
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_missing_saved_xml_is_retrieved_through_consulta_instead_of_the_disabled_download_service(): void
    {
        $xml = '<nfeProc><NFe/></nfeProc>';
        $document = $this->document('nfe', ['xml_base64' => 'invalid-content']);
        $config = $this->mock(NfeConfigService::class);
        $config->shouldReceive('resolveToken')->once()->with(42)->andReturn('company-token');
        $config->shouldReceive('buildSdkParams')->once()->with(42)->andReturn(['token' => 'company-token']);

        $sdk = Mockery::mock('overload:CloudDfe\\SdkPHP\\Nfe');
        $sdk->shouldNotReceive('download');
        $sdk->shouldReceive('consulta')->once()->with(['chave' => $document->document_key])
            ->andReturn((object) ['sucesso' => true, 'xml' => base64_encode($xml)]);

        $limiter = Mockery::mock(IntegranotasRateLimiter::class);
        $limiter->shouldReceive('run')->once()
            ->with('company-token', 'key', $document->document_key, Mockery::type('callable'))
            ->andReturnUsing(fn ($token, $bucket, $key, $callback) => $callback());

        $action = new DownloadFiscalDocumentXmlAction($limiter);

        $this->assertSame($xml, $action->execute($document));
        $this->assertTrue($action->isSuccess());
    }

    public function test_unauthorized_document_cannot_download_even_if_xml_is_saved(): void
    {
        $document = $this->document('nfe', ['xml' => '<NFe/>']);
        $document->nfe_status = NfeStatus::PENDING;
        $limiter = Mockery::mock(IntegranotasRateLimiter::class);
        $limiter->shouldNotReceive('run');
        $action = new DownloadFiscalDocumentXmlAction($limiter);

        $this->assertNull($action->execute($document));
        $this->assertSame('Somente documento fiscal autorizado pode ter XML exportado.', $action->getMessage());
    }

    private function document(string $type, array $payload): FiscalDocument
    {
        $document = new FiscalDocument([
            'company_id' => 42,
            'document_type' => $type,
            'document_key' => str_repeat('1', 44),
            $type.'_status' => NfeStatus::AUTHORIZED,
        ]);
        $document->setRelation('payload', new FiscalDocumentPayload([$type.'_payload' => $payload]));

        return $document;
    }
}
