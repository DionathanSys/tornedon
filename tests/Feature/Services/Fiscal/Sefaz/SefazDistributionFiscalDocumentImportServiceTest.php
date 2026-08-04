<?php

namespace Tests\Feature\Services\Fiscal\Sefaz;

use App\Enum\Payment\Condition;
use App\Enum\Payment\Method as PaymentMethod;
use App\Enum\SefazDistributionDocument\ImportStatus;
use App\Enum\SefazDistributionDocument\ManifestationStatus;
use App\Enum\SefazDistributionDocument\Status;
use App\Models\Company;
use App\Models\FiscalDocument;
use App\Models\Product;
use App\Models\ProductAlternativeUnit;
use App\Models\SefazDistributionDocument;
use App\Models\User;
use App\Services\Fiscal\Sefaz\GenerateSefazDistributionPayableAction;
use App\Services\Fiscal\Sefaz\SefazDistributionFiscalDocumentImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SefazDistributionFiscalDocumentImportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_imports_full_xml_into_fiscal_document_and_items(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $company = $this->createCompany($user);
        $product = Product::query()->create([
            'company_id' => $company->id,
            'product_code' => 'P001',
            'name' => 'Produto importado',
            'unit' => 'UN',
            'is_active' => true,
            'created_by' => $user->id,
        ]);
        ProductAlternativeUnit::query()->create([
            'product_id' => $product->id,
            'unit' => 'CX',
            'conversion_factor' => 12,
        ]);

        $xmlPath = 'sefaz/distribution/company-'.$company->id.'/35260412345678000199550010000003211000000321/full/test.xml';
        Storage::disk('local')->put($xmlPath, $this->fullXml());

        $distributionDocument = SefazDistributionDocument::query()->create([
            'company_id' => $company->id,
            'document_key' => '35260412345678000199550010000003211000000321',
            'nsu' => '000000000000050',
            'schema' => 'procNFe_v4.00.xsd',
            'document_type' => 'nfe',
            'issuer_document' => '12345678000199',
            'issuer_name' => 'Fornecedor Teste',
            'document_number' => '321',
            'document_series' => '1',
            'status' => Status::FULL_XML_AVAILABLE,
            'manifestation_status' => ManifestationStatus::ACCEPTED,
            'import_status' => ImportStatus::READY_TO_IMPORT,
            'full_xml_available' => true,
            'full_xml_path' => $xmlPath,
            'items_json' => [
                [
                    'line' => 1,
                    'product_code' => 'P001',
                    'description' => 'Produto Teste',
                    'product_id' => $product->id,
                    'product_unit' => 'CX',
                ],
            ],
            'import_ready_at' => now(),
            'last_seen_at' => now(),
        ]);

        $fiscalDocument = app(SefazDistributionFiscalDocumentImportService::class)->import($distributionDocument, $user->id);

        $this->assertNotNull($fiscalDocument->id);
        $this->assertDatabaseHas('fiscal_documents', [
            'id' => $fiscalDocument->id,
            'company_id' => $company->id,
            'document_key' => '35260412345678000199550010000003211000000321',
            'document_number' => '321',
            'document_series' => '1',
            'pending' => true,
            'confirmed' => false,
        ]);
        $this->assertSame('Observacao fiscal', $fiscalDocument->additional_tax_information);
        $this->assertSame('Observacao complementar', $fiscalDocument->additional_taxpayer_information);
        $this->assertNull($fiscalDocument->tax_observations);
        $this->assertNull($fiscalDocument->taxpayer_observations);
        $this->assertNull($fiscalDocument->additional_purchase_information);
        $this->assertDatabaseHas('fiscal_document_items', [
            'fiscal_document_id' => $fiscalDocument->id,
            'product_id' => $product->id,
            'product_code' => 'P001',
            'description' => 'Produto Teste',
            'cfop_code' => '1102',
            'unit_of_measure' => 'CX',
            'taxable_unit' => 'CX',
        ]);

        $distributionDocument->refresh();
        $this->assertSame(ImportStatus::IMPORTED, $distributionDocument->import_status);
        $this->assertSame($fiscalDocument->id, $distributionDocument->fiscal_document_id);
        $this->assertSame($user->id, $distributionDocument->imported_by);
        $this->assertNotNull($distributionDocument->imported_at);

        $this->assertDatabaseHas('audit_entries', [
            'company_id' => $company->id,
            'auditable_type' => SefazDistributionDocument::class,
            'auditable_id' => $distributionDocument->id,
            'event' => 'sefaz_distribution.import_succeeded',
        ]);
        $this->assertDatabaseHas('audit_entries', [
            'company_id' => $company->id,
            'auditable_type' => FiscalDocument::class,
            'auditable_id' => $fiscalDocument->id,
            'event' => 'fiscal_document.created',
        ]);
    }

    public function test_it_reuses_existing_fiscal_document_for_same_access_key(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $company = $this->createCompany($user);

        $xmlPath = 'sefaz/distribution/company-'.$company->id.'/35260412345678000199550010000003211000000321/full/test.xml';
        Storage::disk('local')->put($xmlPath, $this->fullXml());

        $existingFiscalDocument = FiscalDocument::query()->create([
            'customer_id' => $this->seedSupplierPartner($company, $user)->id,
            'company_id' => $company->id,
            'status' => \App\Enum\FiscalDocument\Status::PENDING,
            'issued_at' => now()->toDateString(),
            'movement_at' => now()->toDateString(),
            'document_type' => \App\Enum\FiscalDocument\DocumentModel::NFE,
            'document_key' => '35260412345678000199550010000003211000000321',
            'document_number' => '321',
            'document_series' => '1',
            'operation_type' => \App\Enum\FiscalDocument\OperationType::ENTRADA,
            'pending' => true,
            'confirmed' => false,
            'canceled' => false,
            'created_by' => $user->id,
        ]);

        $distributionDocument = SefazDistributionDocument::query()->create([
            'company_id' => $company->id,
            'document_key' => '35260412345678000199550010000003211000000321',
            'nsu' => '000000000000051',
            'schema' => 'procNFe_v4.00.xsd',
            'document_type' => 'nfe',
            'issuer_document' => '12345678000199',
            'issuer_name' => 'Fornecedor Teste',
            'document_number' => '321',
            'document_series' => '1',
            'status' => Status::FULL_XML_AVAILABLE,
            'manifestation_status' => ManifestationStatus::ACCEPTED,
            'import_status' => ImportStatus::READY_TO_IMPORT,
            'full_xml_available' => true,
            'full_xml_path' => $xmlPath,
            'import_ready_at' => now(),
            'last_seen_at' => now(),
        ]);

        $imported = app(SefazDistributionFiscalDocumentImportService::class)->import($distributionDocument, $user->id);

        $this->assertSame($existingFiscalDocument->id, $imported->id);
        $this->assertSame(1, FiscalDocument::query()->where('document_key', '35260412345678000199550010000003211000000321')->count());

        $distributionDocument->refresh();
        $this->assertSame($existingFiscalDocument->id, $distributionDocument->fiscal_document_id);
        $this->assertSame(ImportStatus::IMPORTED, $distributionDocument->import_status);
    }

    public function test_it_blocks_import_when_any_item_is_not_mapped_to_internal_product(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $company = $this->createCompany($user);

        $xmlPath = 'sefaz/distribution/company-'.$company->id.'/35260412345678000199550010000003211000000321/full/test-unmapped.xml';
        Storage::disk('local')->put($xmlPath, $this->fullXml());

        $distributionDocument = SefazDistributionDocument::query()->create([
            'company_id' => $company->id,
            'document_key' => '35260412345678000199550010000003211000000321',
            'nsu' => '000000000000052',
            'schema' => 'procNFe_v4.00.xsd',
            'document_type' => 'nfe',
            'issuer_document' => '12345678000199',
            'issuer_name' => 'Fornecedor Teste',
            'document_number' => '321',
            'document_series' => '1',
            'status' => Status::FULL_XML_AVAILABLE,
            'manifestation_status' => ManifestationStatus::ACCEPTED,
            'import_status' => ImportStatus::READY_TO_IMPORT,
            'full_xml_available' => true,
            'full_xml_path' => $xmlPath,
            'items_json' => [
                [
                    'line' => 1,
                    'product_code' => 'P001',
                    'description' => 'Produto Teste',
                    'product_id' => null,
                ],
            ],
            'import_ready_at' => now(),
            'last_seen_at' => now(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Nao e possivel importar o DF-e sem vincular todos os itens a produtos internos.');

        try {
            app(SefazDistributionFiscalDocumentImportService::class)->import($distributionDocument, $user->id);
        } finally {
            $distributionDocument->refresh();
            $this->assertSame(ImportStatus::IMPORT_ERROR, $distributionDocument->import_status);
            $this->assertStringContainsString('Nao e possivel importar o DF-e sem vincular todos os itens a produtos internos.', (string) $distributionDocument->import_error);
            $this->assertDatabaseCount('fiscal_documents', 0);
            $this->assertDatabaseCount('fiscal_document_items', 0);
        }
    }

    public function test_it_generates_account_payable_directly_from_distribution_xml(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $company = $this->createCompany($user);
        $partner = $this->seedSupplierPartner($company, $user);

        $xmlPath = 'sefaz/distribution/company-'.$company->id.'/35260412345678000199550010000003211000000321/full/payable.xml';
        Storage::disk('local')->put($xmlPath, $this->fullXml());

        $distributionDocument = SefazDistributionDocument::query()->create([
            'company_id' => $company->id,
            'partner_id' => $partner->id,
            'document_key' => '35260412345678000199550010000003211000000321',
            'nsu' => '000000000000053',
            'schema' => 'procNFe_v4.00.xsd',
            'document_type' => 'nfe',
            'issuer_document' => '12345678000199',
            'issuer_name' => 'Fornecedor Teste',
            'document_number' => '321',
            'document_series' => '1',
            'status' => Status::FULL_XML_AVAILABLE,
            'manifestation_status' => ManifestationStatus::ACCEPTED,
            'import_status' => ImportStatus::READY_TO_IMPORT,
            'full_xml_available' => true,
            'full_xml_path' => $xmlPath,
            'total_amount' => 150.99,
            'import_ready_at' => now(),
            'last_seen_at' => now(),
        ]);

        $payable = app(GenerateSefazDistributionPayableAction::class)->execute($distributionDocument, [
            'payment_method' => PaymentMethod::BANK_SLIP->value,
            'payment_condition' => Condition::CASH->value,
            'due_date' => '2026-04-20',
            'description' => 'Conta gerada pelo DF-e',
        ], $user->id);

        $this->assertDatabaseCount('account_payables', 1);
        $this->assertDatabaseCount('account_payable_installments', 1);
        $this->assertSame($partner->id, $payable->supplier_id);
        $this->assertNull($payable->fiscal_document_id);
        $this->assertEquals(150.99, (float) $payable->due_amount);

        $distributionDocument->refresh();
        $this->assertSame($payable->id, $distributionDocument->account_payable_id);
        $this->assertSame('account_payable_generated', $distributionDocument->last_action);
    }

    public function test_it_does_not_duplicate_account_payable_for_same_distribution_document(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $company = $this->createCompany($user);
        $partner = $this->seedSupplierPartner($company, $user);

        $xmlPath = 'sefaz/distribution/company-'.$company->id.'/35260412345678000199550010000003211000000321/full/payable-duplicate.xml';
        Storage::disk('local')->put($xmlPath, $this->fullXml());

        $distributionDocument = SefazDistributionDocument::query()->create([
            'company_id' => $company->id,
            'partner_id' => $partner->id,
            'document_key' => '35260412345678000199550010000003211000000321',
            'nsu' => '000000000000054',
            'schema' => 'procNFe_v4.00.xsd',
            'document_type' => 'nfe',
            'issuer_document' => '12345678000199',
            'issuer_name' => 'Fornecedor Teste',
            'document_number' => '321',
            'document_series' => '1',
            'status' => Status::FULL_XML_AVAILABLE,
            'manifestation_status' => ManifestationStatus::ACCEPTED,
            'import_status' => ImportStatus::READY_TO_IMPORT,
            'full_xml_available' => true,
            'full_xml_path' => $xmlPath,
            'total_amount' => 150.99,
            'import_ready_at' => now(),
            'last_seen_at' => now(),
        ]);

        $first = app(GenerateSefazDistributionPayableAction::class)->execute($distributionDocument, [
            'payment_method' => PaymentMethod::BANK_SLIP->value,
            'payment_condition' => Condition::CASH->value,
            'due_date' => '2026-04-20',
        ], $user->id);
        $second = app(GenerateSefazDistributionPayableAction::class)->execute($distributionDocument->fresh(), [
            'payment_method' => PaymentMethod::PIX->value,
            'payment_condition' => Condition::DAYS_30->value,
            'due_date' => '2026-05-20',
        ], $user->id);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('account_payables', 1);
    }

    public function test_import_links_previously_generated_account_payable_to_fiscal_document(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $company = $this->createCompany($user);
        $partner = $this->seedSupplierPartner($company, $user);
        $product = Product::query()->create([
            'company_id' => $company->id,
            'product_code' => 'P001',
            'name' => 'Produto importado',
            'unit' => 'UN',
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        $xmlPath = 'sefaz/distribution/company-'.$company->id.'/35260412345678000199550010000003211000000321/full/payable-import.xml';
        Storage::disk('local')->put($xmlPath, $this->fullXml());

        $distributionDocument = SefazDistributionDocument::query()->create([
            'company_id' => $company->id,
            'partner_id' => $partner->id,
            'document_key' => '35260412345678000199550010000003211000000321',
            'nsu' => '000000000000055',
            'schema' => 'procNFe_v4.00.xsd',
            'document_type' => 'nfe',
            'issuer_document' => '12345678000199',
            'issuer_name' => 'Fornecedor Teste',
            'document_number' => '321',
            'document_series' => '1',
            'status' => Status::FULL_XML_AVAILABLE,
            'manifestation_status' => ManifestationStatus::ACCEPTED,
            'import_status' => ImportStatus::READY_TO_IMPORT,
            'full_xml_available' => true,
            'full_xml_path' => $xmlPath,
            'total_amount' => 150.99,
            'items_json' => [
                [
                    'line' => 1,
                    'product_code' => 'P001',
                    'description' => 'Produto Teste',
                    'product_id' => $product->id,
                ],
            ],
            'import_ready_at' => now(),
            'last_seen_at' => now(),
        ]);

        $payable = app(GenerateSefazDistributionPayableAction::class)->execute($distributionDocument, [
            'payment_method' => PaymentMethod::BANK_SLIP->value,
            'payment_condition' => Condition::CASH->value,
            'due_date' => '2026-04-20',
        ], $user->id);

        $fiscalDocument = app(SefazDistributionFiscalDocumentImportService::class)->import($distributionDocument->fresh(), $user->id);

        $payable->refresh();
        $this->assertSame($fiscalDocument->id, $payable->fiscal_document_id);
        $this->assertSame($payable->id, $distributionDocument->fresh()->account_payable_id);
        $this->assertSame(ImportStatus::IMPORTED, $distributionDocument->fresh()->import_status);
    }

    private function createCompany(User $user): Company
    {
        return Company::query()->create([
            'name' => 'Empresa Importacao '.Str::uuid(),
            'document_number' => '22345678000188',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'email' => Str::uuid().'@example.com',
            'certificate' => 'certificados/teste.pfx',
            'is_active' => true,
            'created_by' => $user->id,
        ]);
    }

    private function seedSupplierPartner(Company $company, User $user): \App\Models\Partner
    {
        return app(\App\Services\Fiscal\Sefaz\SefazDistributionDocumentService::class)
            ->resolveOrCreatePartner($company, '12345678000199', 'Fornecedor Teste');
    }

    private function fullXml(): string
    {
        return <<<'XML'
<procNFe xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00">
  <NFe>
    <infNFe Id="NFe35260412345678000199550010000003211000000321" versao="4.00">
      <ide>
        <natOp>VENDA DENTRO DO ESTADO</natOp>
        <serie>1</serie>
        <nNF>321</nNF>
        <dhEmi>2026-04-19T10:00:00-03:00</dhEmi>
        <tpNF>0</tpNF>
        <finNFe>1</finNFe>
        <indFinal>0</indFinal>
        <indPres>0</indPres>
      </ide>
      <emit>
        <CNPJ>12345678000199</CNPJ>
        <xNome>Fornecedor Teste</xNome>
        <IE>123456789</IE>
      </emit>
      <dest>
        <CNPJ>22345678000188</CNPJ>
        <xNome>Empresa Destinataria</xNome>
      </dest>
      <det nItem="1">
        <prod>
          <cProd>P001</cProd>
          <cEAN>7890000000012</cEAN>
          <xProd>Produto Teste</xProd>
          <NCM>84713012</NCM>
          <CFOP>1102</CFOP>
          <uCom>UN</uCom>
          <qCom>2.0000</qCom>
          <vUnCom>75.4950</vUnCom>
          <vProd>150.99</vProd>
          <uTrib>UN</uTrib>
          <qTrib>2.0000</qTrib>
          <vUnTrib>75.4950</vUnTrib>
        </prod>
        <imposto>
          <ICMS><ICMS00><orig>0</orig><CST>00</CST></ICMS00></ICMS>
          <PIS><PISAliq><CST>01</CST></PISAliq></PIS>
          <COFINS><COFINSAliq><CST>01</CST></COFINSAliq></COFINS>
        </imposto>
      </det>
      <total>
        <ICMSTot>
          <vNF>150.99</vNF>
        </ICMSTot>
      </total>
      <transp>
        <modFrete>9</modFrete>
      </transp>
      <pag>
        <detPag>
          <tPag>90</tPag>
          <vPag>150.99</vPag>
        </detPag>
      </pag>
      <infAdic>
        <infAdFisco>Observacao fiscal</infAdFisco>
        <infCpl>Observacao complementar</infCpl>
      </infAdic>
    </infNFe>
  </NFe>
  <protNFe>
    <infProt>
      <nProt>135260000000001</nProt>
      <dhRecbto>2026-04-19T10:05:00-03:00</dhRecbto>
    </infProt>
  </protNFe>
</procNFe>
XML;
    }
}
