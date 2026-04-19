<?php

namespace Tests\Feature\Jobs;

use App\Enum\SefazDistributionDocument\ManifestationStatus;
use App\Enum\SefazDistributionDocument\Status;
use App\Jobs\RefreshSefazDistributionDocumentJob;
use App\Models\Company;
use App\Models\SefazDistributionDocument;
use App\Models\User;
use App\Services\Fiscal\Sefaz\DTO\DfeDistributionDocument;
use App\Services\Fiscal\Sefaz\DTO\DfeDistributionResult;
use App\Services\Fiscal\Sefaz\SefazDfeDistributionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class RefreshSefazDistributionDocumentJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_promotes_document_to_full_xml_available(): void
    {
        Storage::fake('local');

        $company = $this->createCompany();
        $document = SefazDistributionDocument::query()->create([
            'company_id' => $company->id,
            'document_key' => '35260412345678000199550010000003211000000321',
            'nsu' => '000000000000050',
            'schema' => 'resNFe_v1.01.xsd',
            'document_type' => 'nfe',
            'status' => Status::MANIFESTED_WAITING_FULL_XML,
            'manifestation_status' => ManifestationStatus::ACCEPTED,
            'full_xml_available' => false,
            'summary_xml_path' => 'sefaz/distribution/company-' . $company->id . '/summary.xml',
            'last_seen_at' => now(),
        ]);

        $service = Mockery::mock(SefazDfeDistributionService::class);
        $service->shouldReceive('distribute')
            ->once()
            ->andReturn(new DfeDistributionResult(
                success: true,
                statusCode: '138',
                statusMessage: 'Documento localizado',
                ultNsu: '000000000000050',
                maxNsu: '000000000000050',
                rawXml: '<retDistDFeInt/>',
                documents: [
                    new DfeDistributionDocument(
                        nsu: '000000000000050',
                        schema: 'procNFe_v4.00.xsd',
                        xml: $this->fullXml(),
                        accessKey: '35260412345678000199550010000003211000000321',
                    ),
                ],
            ));

        $this->app->instance(SefazDfeDistributionService::class, $service);

        $job = new RefreshSefazDistributionDocumentJob($document->id, 1);
        $job->handle(
            app(SefazDfeDistributionService::class),
            app(\App\Services\Fiscal\Sefaz\SefazDistributionDocumentService::class),
            app(\App\Services\Fiscal\Sefaz\SefazDfeStorageService::class),
        );

        $document->refresh();

        $this->assertTrue($document->full_xml_available);
        $this->assertSame(Status::FULL_XML_AVAILABLE, $document->status);
        $this->assertNotNull($document->import_ready_at);
        $this->assertCount(1, $document->items_json ?? []);
        $this->assertNotNull($document->full_xml_path);
        Storage::disk('local')->assertExists($document->full_xml_path);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    private function createCompany(): Company
    {
        $user = User::factory()->create();

        return Company::query()->create([
            'name' => 'Empresa Refresh ' . Str::uuid(),
            'document_number' => '12345678000199',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'email' => Str::uuid() . '@example.com',
            'certificate' => 'certificados/teste.pfx',
            'is_active' => true,
            'created_by' => $user->id,
        ]);
    }

    private function fullXml(): string
    {
        return <<<XML
<procNFe xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00">
  <NFe>
    <infNFe Id="NFe35260412345678000199550010000003211000000321" versao="4.00">
      <ide>
        <serie>1</serie>
        <nNF>321</nNF>
        <dhEmi>2026-04-19T10:00:00-03:00</dhEmi>
      </ide>
      <emit>
        <CNPJ>12345678000199</CNPJ>
        <xNome>Fornecedor Teste</xNome>
      </emit>
      <det nItem="1">
        <prod>
          <cProd>P001</cProd>
          <xProd>Produto Teste</xProd>
          <NCM>84713012</NCM>
          <CFOP>1102</CFOP>
          <uCom>UN</uCom>
          <qCom>2.0000</qCom>
          <vUnCom>75.4950</vUnCom>
          <vProd>150.99</vProd>
        </prod>
      </det>
      <total>
        <ICMSTot>
          <vNF>150.99</vNF>
        </ICMSTot>
      </total>
    </infNFe>
  </NFe>
</procNFe>
XML;
    }
}
