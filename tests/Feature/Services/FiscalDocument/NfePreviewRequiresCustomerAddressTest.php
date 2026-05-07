<?php

namespace Tests\Feature\Services\FiscalDocument;

use App\Services\Fiscal\NfeConfigService;
use App\Services\FiscalDocument\NfeDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Feature\Services\FiscalDocument\Support\BuildsNfePreviewDocuments;
use Tests\TestCase;

class NfePreviewRequiresCustomerAddressTest extends TestCase
{
    use BuildsNfePreviewDocuments;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_preview_should_block_document_without_customer_address(): void
    {
        [$user, $document] = $this->createReadyPreviewDocument(withCustomerAddress: false);

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
                'sucesso' => true,
                'pdf' => 'fake-pdf',
                'xml' => 'fake-xml',
            ]);

        $service = app(NfeDocumentService::class);
        $result = $service->preview($document, $user->id);

        $this->assertNull($result);
    }
}
