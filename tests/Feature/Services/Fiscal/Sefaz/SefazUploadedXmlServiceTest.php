<?php

namespace Tests\Feature\Services\Fiscal\Sefaz;

use App\Enum\SefazDistributionDocument\ImportStatus;
use App\Enum\SefazDistributionDocument\ManifestationStatus;
use App\Enum\SefazDistributionDocument\Status;
use App\Models\Company;
use App\Models\User;
use App\Services\Fiscal\Sefaz\SefazUploadedXmlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SefazUploadedXmlServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_registers_uploaded_full_xml_as_distribution_document(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $company = Company::query()->create([
            'name' => 'Empresa Upload ' . Str::uuid(),
            'document_number' => '22345678000188',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'email' => Str::uuid() . '@example.com',
            'certificate' => 'certificados/teste.pfx',
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        $record = app(SefazUploadedXmlService::class)->register(
            $company,
            $this->fullXml(),
            '42260445790457000185557000000000201122876237.xml',
        );

        $this->assertTrue($record->full_xml_available);
        $this->assertSame(Status::FULL_XML_AVAILABLE, $record->status);
        $this->assertSame(ImportStatus::READY_TO_IMPORT, $record->import_status);
        $this->assertSame(ManifestationStatus::ACCEPTED, $record->manifestation_status);
        $this->assertSame('procNFe_v4.00.xsd', $record->schema);
        $this->assertNotNull($record->full_xml_path);
        Storage::disk('local')->assertExists($record->full_xml_path);
    }

    private function fullXml(): string
    {
        return <<<XML
<procNFe xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00">
  <NFe>
    <infNFe Id="NFe42260445790457000185557000000000201122876237" versao="4.00">
      <ide>
        <serie>0</serie>
        <nNF>20</nNF>
        <dhEmi>2026-04-23T10:00:00-03:00</dhEmi>
      </ide>
      <emit>
        <CNPJ>12345678000199</CNPJ>
        <xNome>Fornecedor XML</xNome>
      </emit>
      <det nItem="1">
        <prod>
          <cProd>ITEM-01</cProd>
          <xProd>Produto XML</xProd>
          <NCM>84713012</NCM>
          <CFOP>1102</CFOP>
          <uCom>UN</uCom>
          <qCom>1.0000</qCom>
          <vUnCom>100.00</vUnCom>
          <vProd>100.00</vProd>
        </prod>
      </det>
      <total>
        <ICMSTot>
          <vNF>100.00</vNF>
        </ICMSTot>
      </total>
    </infNFe>
  </NFe>
</procNFe>
XML;
    }
}
