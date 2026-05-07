<?php

namespace Tests\Feature\Services\FiscalDocument;

use App\Services\Fiscal\NfeConfigService;
use App\Services\FiscalDocument\NfeDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Feature\Services\FiscalDocument\Support\BuildsNfePreviewDocuments;
use Tests\TestCase;

class NfePreviewDoesNotPersistTemporaryNumberTest extends TestCase
{
    use BuildsNfePreviewDocuments;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_preview_api_error_should_not_persist_temporary_document_number(): void
    {
        [$user, $document] = $this->createReadyPreviewDocument();

        $this->assertNull($document->document_number);
        $this->assertNull($document->document_series);

        $config = Mockery::mock(NfeConfigService::class);
        $config->shouldReceive('resolveSerie')->andReturn('1');
        $config->shouldReceive('buildSdkParams')->andReturn([
            'token' => 'fake-token',
            'ambiente' => 2,
            'options' => [],
        ]);
        $this->app->instance(NfeConfigService::class, $config);

        $sdk = Mockery::mock('overload:CloudDfe\SdkPHP\Nfe');
        $sdk->shouldReceive('preview')
            ->once()
            ->andReturn((object) [
                'sucesso' => false,
                'codigo' => 5002,
                'mensagem' => 'Erro de validacao no preview',
                'erros' => [
                    ['campo' => 'valor_total', 'erro' => 'invalido'],
                ],
            ]);

        $service = app(NfeDocumentService::class);
        $result = $service->preview($document, $user->id);

        $this->assertNull($result);

        $document->refresh();

        $this->assertNull($document->document_number);
        $this->assertNull($document->document_series);
    }
}
