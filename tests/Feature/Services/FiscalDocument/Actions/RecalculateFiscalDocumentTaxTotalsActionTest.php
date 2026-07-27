<?php

namespace Tests\Feature\Services\FiscalDocument\Actions;

use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\Status;
use App\Models\Company;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentItem;
use App\Models\Partner;
use App\Models\User;
use App\Services\FiscalDocument\Actions\RecalculateFiscalDocumentTaxTotalsAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecalculateFiscalDocumentTaxTotalsActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_writes_totals_to_split_tax_details(): void
    {
        $document = $this->makeFiscalDocument();

        FiscalDocumentItem::query()->create([
            'fiscal_document_id' => $document->id,
            'product_id' => null,
            'product_code' => 'SKU-001',
            'item_number' => 1,
            'product_origin' => '0',
            'ncm_code' => '01012100',
            'cfop_code' => '5102',
            'quantity' => 1,
            'unit_of_measure' => 'UN',
            'unit_price' => 100,
            'total_price' => 100,
            'discount_amount' => 10,
            'freight_amount' => 5,
            'insurance_amount' => 2,
            'other_expenses_amount' => 3,
            'included_in_total' => true,
            'tax_data' => [
                'imposto' => [
                    'icms' => [
                        'valor_base_calculo' => 90,
                        'valor' => 16.2,
                    ],
                    'pis' => [
                        'valor' => 1.49,
                    ],
                    'cofins' => [
                        'valor' => 6.84,
                    ],
                ],
            ],
        ]);

        $updated = app(RecalculateFiscalDocumentTaxTotalsAction::class)->execute($document);

        $this->assertDatabaseHas('fiscal_document_tax_details', [
            'fiscal_document_id' => $document->id,
            'company_id' => $document->company_id,
        ]);

        $updated->load('taxDetail');

        $this->assertNull($updated->taxDetail->tax_data);
        $this->assertSame([], $updated->taxDetail->fiscal_metadata);
        $this->assertSame('100.00', data_get($updated->taxDetail->tax_totals, 'valor_produtos'));

        $this->assertSame('100.00', data_get($updated->tax_data, 'totais.valor_produtos'));
        $this->assertSame('100.00', data_get($updated->tax_data, 'totais.valor_nota'));
        $this->assertSame('90.00', data_get($updated->tax_data, 'totais.base_calculo_icms'));
        $this->assertSame('16.20', data_get($updated->tax_data, 'totais.valor_icms'));
        $this->assertSame('1.49', data_get($updated->tax_data, 'totais.valor_pis'));
        $this->assertSame('6.84', data_get($updated->tax_data, 'totais.valor_cofins'));
    }

    private function makeFiscalDocument(): FiscalDocument
    {
        $user = User::factory()->create();
        $company = Company::query()->create([
            'name' => 'Empresa Fiscal',
            'document_number' => '12345678000199',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);
        $customer = Partner::query()->create([
            'name' => 'Cliente Fiscal',
            'document_type' => 'CNPJ',
            'document_number' => '22345678000188',
            'created_by' => $user->id,
        ]);

        return FiscalDocument::query()->create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'status' => Status::PENDING->value,
            'document_type' => DocumentModel::NFE->value,
            'issued_at' => now()->toDateString(),
            'movement_at' => now()->toDateString(),
        ]);
    }
}
