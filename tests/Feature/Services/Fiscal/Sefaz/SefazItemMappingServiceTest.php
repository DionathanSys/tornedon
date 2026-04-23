<?php

namespace Tests\Feature\Services\Fiscal\Sefaz;

use App\Enum\SefazDistributionDocument\ManifestationStatus;
use App\Enum\SefazDistributionDocument\Status;
use App\Models\Company;
use App\Models\Product;
use App\Models\SefazDistributionDocument;
use App\Models\User;
use App\Services\Fiscal\Sefaz\DTO\DfeDistributionDocument;
use App\Services\Fiscal\Sefaz\SefazDistributionDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SefazItemMappingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_persists_item_mapping_when_user_links_item_to_product(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $company = $this->createCompany($user);
        $product = Product::query()->create([
            'company_id' => $company->id,
            'product_code' => 'PROD-01',
            'name' => 'Produto interno',
            'unit' => 'UN',
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        $documentService = app(SefazDistributionDocumentService::class);
        $partner = $documentService->resolveOrCreatePartner($company, '12345678000199', 'Fornecedor Mapeado');

        $distributionDocument = SefazDistributionDocument::query()->create([
            'company_id' => $company->id,
            'partner_id' => $partner->id,
            'document_key' => '35260412345678000199550010000003211000000321',
            'nsu' => '000000000000050',
            'schema' => 'procNFe_v4.00.xsd',
            'document_type' => 'nfe',
            'status' => Status::FULL_XML_AVAILABLE,
            'manifestation_status' => ManifestationStatus::ACCEPTED,
            'full_xml_available' => true,
            'items_json' => [
                [
                    'line' => 1,
                    'product_code' => 'ITEM-XML-01',
                    'description' => 'Item XML 01',
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                ],
            ],
            'import_ready_at' => now(),
            'last_seen_at' => now(),
        ]);

        $documentService->updateItemMappings($distributionDocument, $distributionDocument->items_json, $user->id);

        $this->assertDatabaseHas('sefaz_item_mappings', [
            'company_id' => $company->id,
            'partner_id' => $partner->id,
            'product_id' => $product->id,
            'xml_item_code' => 'ITEM-XML-01',
        ]);
    }

    public function test_it_applies_saved_mapping_when_same_partner_item_appears_again(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $company = $this->createCompany($user);
        $product = Product::query()->create([
            'company_id' => $company->id,
            'product_code' => 'PROD-01',
            'name' => 'Produto interno',
            'unit' => 'UN',
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        $documentService = app(SefazDistributionDocumentService::class);
        $partner = $documentService->resolveOrCreatePartner($company, '12345678000199', 'Fornecedor Mapeado');

        $mappedDocument = SefazDistributionDocument::query()->create([
            'company_id' => $company->id,
            'partner_id' => $partner->id,
            'document_key' => '35260412345678000199550010000003211000000320',
            'nsu' => '000000000000049',
            'schema' => 'procNFe_v4.00.xsd',
            'document_type' => 'nfe',
            'status' => Status::FULL_XML_AVAILABLE,
            'manifestation_status' => ManifestationStatus::ACCEPTED,
            'full_xml_available' => true,
            'items_json' => [
                [
                    'line' => 1,
                    'product_code' => 'ITEM-XML-01',
                    'description' => 'Item XML 01',
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                ],
            ],
            'import_ready_at' => now(),
            'last_seen_at' => now(),
        ]);

        $documentService->updateItemMappings($mappedDocument, $mappedDocument->items_json, $user->id);

        $result = $documentService->persistFromDistribution(
            $company,
            new DfeDistributionDocument(
                nsu: '000000000000051',
                schema: 'procNFe_v4.00.xsd',
                xml: $this->fullXml(),
                accessKey: '35260412345678000199550010000003211000000321',
            ),
            'sefaz/distribution/raw.xml',
        );

        $this->assertNotNull($result);
        $this->assertTrue($result->full_xml_available);
        $this->assertSame($partner->id, $result->partner_id);
        $this->assertSame($product->id, data_get($result->items_json, '0.product_id'));
        $this->assertSame($product->name, data_get($result->items_json, '0.product_name'));
    }

    private function createCompany(User $user): Company
    {
        return Company::query()->create([
            'name' => 'Empresa Mapping ' . Str::uuid(),
            'document_number' => '22345678000188',
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
        <xNome>Fornecedor Mapeado</xNome>
      </emit>
      <det nItem="1">
        <prod>
          <cProd>ITEM-XML-01</cProd>
          <xProd>Item XML 01</xProd>
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
