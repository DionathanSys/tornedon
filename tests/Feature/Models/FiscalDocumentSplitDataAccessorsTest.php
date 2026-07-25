<?php

namespace Tests\Feature\Models;

use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\Status;
use App\Models\Company;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentPayload;
use App\Models\FiscalDocumentTaxDetail;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiscalDocumentSplitDataAccessorsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reads_split_tax_and_payload_data_when_available(): void
    {
        $document = $this->makeFiscalDocument([
            'freight_data' => ['source' => 'legacy-freight'],
            'payment_data' => ['source' => 'legacy-payment'],
            'tax_data' => ['source' => 'legacy-tax'],
            'nfe_payload' => ['source' => 'legacy-nfe'],
            'nfse_payload' => ['source' => 'legacy-nfse'],
        ]);

        FiscalDocumentTaxDetail::query()->create([
            'company_id' => $document->company_id,
            'fiscal_document_id' => $document->id,
            'freight_data' => ['source' => 'split-freight'],
            'payment_data' => ['source' => 'split-payment'],
            'fiscal_metadata' => ['source' => 'split-tax'],
            'tax_totals' => ['valor_nota' => '100.00'],
        ]);

        FiscalDocumentPayload::query()->create([
            'company_id' => $document->company_id,
            'fiscal_document_id' => $document->id,
            'nfe_payload' => ['source' => 'split-nfe'],
            'nfse_payload' => ['source' => 'split-nfse'],
        ]);

        $document = FiscalDocument::query()->with(['taxDetail', 'payload'])->findOrFail($document->id);

        $this->assertSame(['source' => 'split-freight'], $document->freight_data);
        $this->assertSame(['source' => 'split-payment'], $document->payment_data);
        $this->assertSame([
            'source' => 'split-tax',
            'totais' => ['valor_nota' => '100.00'],
        ], $document->tax_data);
        $this->assertSame(['source' => 'split-nfe'], $document->nfe_payload);
        $this->assertSame(['source' => 'split-nfse'], $document->nfse_payload);
    }

    public function test_it_falls_back_to_legacy_columns_when_split_records_do_not_exist(): void
    {
        $document = $this->makeFiscalDocument([
            'freight_data' => ['source' => 'legacy-freight'],
            'payment_data' => ['source' => 'legacy-payment'],
            'tax_data' => ['source' => 'legacy-tax'],
            'nfe_payload' => ['source' => 'legacy-nfe'],
            'nfse_payload' => ['source' => 'legacy-nfse'],
        ])->fresh();

        $this->assertSame(['source' => 'legacy-freight'], $document->freight_data);
        $this->assertSame(['source' => 'legacy-payment'], $document->payment_data);
        $this->assertSame(['source' => 'legacy-tax'], $document->tax_data);
        $this->assertSame(['source' => 'legacy-nfe'], $document->nfe_payload);
        $this->assertSame(['source' => 'legacy-nfse'], $document->nfse_payload);
    }

    public function test_it_falls_back_to_legacy_split_tax_data_when_new_tax_fields_do_not_exist(): void
    {
        $document = $this->makeFiscalDocument(['tax_data' => ['source' => 'legacy-column']]);

        FiscalDocumentTaxDetail::query()->create([
            'company_id' => $document->company_id,
            'fiscal_document_id' => $document->id,
            'tax_data' => ['source' => 'legacy-split'],
        ]);

        $document = FiscalDocument::query()->with('taxDetail')->findOrFail($document->id);

        $this->assertSame(['source' => 'legacy-split'], $document->tax_data);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeFiscalDocument(array $overrides = []): FiscalDocument
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
            ...$overrides,
        ]);
    }
}
