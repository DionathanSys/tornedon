<?php

namespace Tests\Feature\Services\Fiscal;

use App\Domain\DTO\Fiscal\FiscalDecisionDTO;
use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\Status;
use App\Models\Company;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentItem;
use App\Models\Partner;
use App\Models\User;
use App\Services\Fiscal\Actions\PersistFiscalSnapshotAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PersistFiscalSnapshotActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_uses_net_taxable_base_for_nfe_items(): void
    {
        [$document, $item] = $this->createDocumentWithItem(DocumentModel::NFE, [
            'product_code' => 'PRD-001',
            'description' => 'Produto Teste',
            'quantity' => 1,
            'unit_of_measure' => 'UN',
            'unit_price' => 100,
            'total_price' => 100,
            'discount_amount' => 15,
            'product_origin' => '0',
            'ncm_code' => '84733049',
        ]);

        $decision = new FiscalDecisionDTO(
            cfop: '5102',
            cstIcms: '00',
            csosn: null,
            modBcIcms: null,
            aliquotaIcms: 18,
            reducaoBaseIcms: null,
            modBcSt: null,
            aliquotaMvaSt: null,
            aliquotaSt: null,
            reducaoBaseSt: null,
            cstPis: '01',
            aliquotaPis: 1.65,
            cstCofins: '01',
            aliquotaCofins: 7.6,
            cstIpi: null,
            aliquotaIpi: null,
            enquadramentoIpi: null,
        );

        $action = app(PersistFiscalSnapshotAction::class);

        $this->assertTrue($action->execute($document, [1 => $decision]), $action->getMessage() ?? '');

        $item->refresh();

        $this->assertSame(85.0, (float) data_get($item->tax_data, 'imposto.icms.valor_base_calculo'));
        $this->assertSame(85.0, (float) data_get($item->tax_data, 'imposto.pis.valor_base_calculo'));
        $this->assertSame(85.0, (float) data_get($item->tax_data, 'imposto.cofins.valor_base_calculo'));
    }

    public function test_it_uses_net_taxable_base_for_nfse_items(): void
    {
        [$document, $item] = $this->createDocumentWithItem(DocumentModel::NFSE, [
            'description' => 'Servico Teste',
            'quantity' => 1,
            'unit_of_measure' => 'UN',
            'unit_price' => 100,
            'total_price' => 100,
            'discount_amount' => 15,
            'municipal_tax_code' => '01.01',
        ]);

        $decision = new FiscalDecisionDTO(
            cfop: null,
            cstIcms: null,
            csosn: null,
            modBcIcms: null,
            aliquotaIcms: null,
            reducaoBaseIcms: null,
            modBcSt: null,
            aliquotaMvaSt: null,
            aliquotaSt: null,
            reducaoBaseSt: null,
            cstPis: null,
            aliquotaPis: null,
            cstCofins: null,
            aliquotaCofins: null,
            cstIpi: null,
            aliquotaIpi: null,
            enquadramentoIpi: null,
            issAliquota: 5.0,
            issRetido: false,
            issExigibilidade: '1',
        );

        $action = app(PersistFiscalSnapshotAction::class);

        $this->assertTrue($action->execute($document, [1 => $decision]), $action->getMessage() ?? '');

        $item->refresh();

        $this->assertSame(4.25, (float) data_get($item->tax_data, 'iss.valor'));
        $this->assertSame(4.25, (float) $item->iss_amount);
    }

    public function test_it_does_not_highlight_own_icms_for_simples_csosn_102(): void
    {
        [$document, $item] = $this->createDocumentWithItem(DocumentModel::NFE, [
            'product_code' => 'PRD-102',
            'description' => 'Produto Simples',
            'quantity' => 1,
            'unit_of_measure' => 'UN',
            'unit_price' => 100,
            'total_price' => 100,
            'discount_amount' => 0,
            'product_origin' => '0',
            'ncm_code' => '84733049',
        ]);

        $decision = new FiscalDecisionDTO(
            cfop: '5102',
            cstIcms: null,
            csosn: '102',
            modBcIcms: '3',
            aliquotaIcms: 18,
            reducaoBaseIcms: null,
            modBcSt: null,
            aliquotaMvaSt: null,
            aliquotaSt: null,
            reducaoBaseSt: null,
            cstPis: '49',
            aliquotaPis: 0.65,
            cstCofins: '49',
            aliquotaCofins: 3.0,
            cstIpi: null,
            aliquotaIpi: null,
            enquadramentoIpi: null,
        );

        $action = app(PersistFiscalSnapshotAction::class);

        $this->assertTrue($action->execute($document, [1 => $decision]), $action->getMessage() ?? '');

        $item->refresh();

        $this->assertSame('102', data_get($item->tax_data, 'imposto.icms.situacao_tributaria'));
        $this->assertNull(data_get($item->tax_data, 'imposto.icms.valor_base_calculo'));
        $this->assertNull(data_get($item->tax_data, 'imposto.icms.aliquota'));
        $this->assertNull(data_get($item->tax_data, 'imposto.icms.valor'));
    }

    /**
     * @param  array<string, mixed>  $itemAttributes
     * @return array{FiscalDocument, FiscalDocumentItem}
     */
    private function createDocumentWithItem(DocumentModel $documentType, array $itemAttributes): array
    {
        $user = User::factory()->create();

        $company = Company::query()->create([
            'name' => 'Empresa Snapshot',
            'document_number' => '12345678000199',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);

        $customer = Partner::query()->create([
            'name' => 'Cliente Snapshot',
            'document_type' => 'CNPJ',
            'document_number' => '22345678000188',
            'created_by' => $user->id,
        ]);

        $document = FiscalDocument::query()->create([
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'status' => Status::PENDING->value,
            'document_type' => $documentType->value,
            'issued_at' => now()->toDateString(),
            'movement_at' => now()->toDateString(),
            'document_number' => '100',
            'document_series' => '1',
            'created_by' => $user->id,
        ]);

        $item = FiscalDocumentItem::query()->create(array_merge([
            'fiscal_document_id' => $document->id,
            'item_number' => 1,
            'included_in_total' => true,
            'created_by' => $user->id,
        ], $itemAttributes));

        return [$document->fresh('items'), $item];
    }
}
