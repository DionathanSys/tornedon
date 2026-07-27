<?php

namespace Tests\Feature\Services\FiscalDocument\Actions;

use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\Status;
use App\Models\Company;
use App\Models\FiscalDocument;
use App\Models\Partner;
use App\Models\User;
use App\Services\FiscalDocument\Actions\UpsertFiscalDocumentPayloadAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpsertFiscalDocumentPayloadActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_writes_payload_to_split_table(): void
    {
        $document = $this->makeFiscalDocument();

        app(UpsertFiscalDocumentPayloadAction::class)->execute($document, [
            'nfe_payload' => ['source' => 'split-nfe'],
            'nfse_payload' => ['source' => 'split-nfse'],
        ]);

        $this->assertDatabaseHas('fiscal_document_payloads', [
            'fiscal_document_id' => $document->id,
            'company_id' => $document->company_id,
        ]);

        $document = $document->fresh()->load('payload');

        $this->assertSame(['source' => 'split-nfe'], $document->nfe_payload);
        $this->assertSame(['source' => 'split-nfse'], $document->nfse_payload);
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
