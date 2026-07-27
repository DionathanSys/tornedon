<?php

namespace Tests\Feature\Services\FiscalDocument;

use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\Status;
use App\Models\Company;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentPayload;
use App\Models\Partner;
use App\Models\User;
use App\Services\FiscalDocument\Actions\StoreFiscalDocumentAttachmentsAction;
use App\Services\FiscalDocument\NfeDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class StoreFiscalDocumentAttachmentsActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_stores_danfe_and_xml_as_fiscal_document_attachments(): void
    {
        Storage::fake('local');

        $fiscalDocument = $this->createFiscalDocument();
        $fiscalDocument->update([
            'document_number' => '1234',
            'document_key' => 'NFEKEY123',
        ]);
        FiscalDocumentPayload::query()->create([
            'company_id' => $fiscalDocument->company_id,
            'fiscal_document_id' => $fiscalDocument->id,
            'nfe_payload' => ['xml' => '<NFe><infNFe>ok</infNFe></NFe>'],
        ]);

        $mock = Mockery::mock(NfeDocumentService::class);
        $mock->shouldReceive('danfe')->once()->andReturn(base64_encode('pdf-content'));
        $this->app->instance(NfeDocumentService::class, $mock);

        $action = app(StoreFiscalDocumentAttachmentsAction::class);
        $ok = $action->execute($fiscalDocument->fresh());

        $this->assertTrue($ok, (string) $action->getMessage());

        $attachments = $fiscalDocument->fresh()->attachments()->get();
        $this->assertCount(2, $attachments);
        $this->assertNotNull($attachments->first(fn ($attachment) => data_get($attachment->metadata, 'kind') === 'danfe'));
        $this->assertNotNull($attachments->first(fn ($attachment) => data_get($attachment->metadata, 'kind') === 'xml'));
    }

    public function test_it_is_idempotent_for_same_document_version(): void
    {
        Storage::fake('local');

        $fiscalDocument = $this->createFiscalDocument();
        $fiscalDocument->update([
            'document_number' => '1234',
            'document_key' => 'NFEKEY123',
        ]);
        FiscalDocumentPayload::query()->create([
            'company_id' => $fiscalDocument->company_id,
            'fiscal_document_id' => $fiscalDocument->id,
            'nfe_payload' => ['xml' => '<NFe><infNFe>ok</infNFe></NFe>'],
        ]);

        $mock = Mockery::mock(NfeDocumentService::class);
        $mock->shouldReceive('danfe')->twice()->andReturn(base64_encode('pdf-content'));
        $this->app->instance(NfeDocumentService::class, $mock);

        $action = app(StoreFiscalDocumentAttachmentsAction::class);
        $this->assertTrue($action->execute($fiscalDocument->fresh()));
        $this->assertTrue($action->execute($fiscalDocument->fresh()));

        $this->assertSame(2, $fiscalDocument->fresh()->attachments()->count());
    }

    private function createFiscalDocument(): FiscalDocument
    {
        $user = User::factory()->create();
        $company = Company::query()->create([
            'name' => 'Empresa Anexos',
            'document_number' => '12345678000199',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);
        $customer = Partner::query()->create([
            'name' => 'Cliente Anexos',
            'document_type' => 'CNPJ',
            'document_number' => '22345678000188',
            'created_by' => $user->id,
        ]);

        return FiscalDocument::query()->create([
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'status' => Status::PENDING->value,
            'issued_at' => now()->toDateString(),
            'movement_at' => now()->toDateString(),
            'document_type' => DocumentModel::NFE->value,
            'pending' => true,
            'confirmed' => false,
            'canceled' => false,
        ]);
    }
}
