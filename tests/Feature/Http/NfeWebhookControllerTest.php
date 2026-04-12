<?php

namespace Tests\Feature\Http;

use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\NfeStatus;
use App\Enum\FiscalDocument\Status;
use App\Models\Company;
use App\Models\FiscalDocument;
use App\Models\Partner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NfeWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_marks_rejected_document_as_pending_for_retry(): void
    {
        $document = $this->createFiscalDocument(DocumentModel::NFE);
        $document->update([
            'status' => Status::CONFIRMED->value,
            'nfe_status' => NfeStatus::IN_PROCESSING->value,
            'document_key' => 'WEBHOOK-NFE-REJECTED',
        ]);

        $response = $this->postJson(route('webhook.nfe'), [
            'chave' => 'WEBHOOK-NFE-REJECTED',
            'status' => 'rejeitada',
            'codigo' => '999',
            'mensagem' => 'Documento rejeitado',
        ]);

        $response->assertOk();

        $document->refresh();

        $this->assertSame(NfeStatus::REJECTED, $document->nfe_status);
        $this->assertSame(Status::PENDING, $document->status);
        $this->assertSame('webhook', $document->errors_messages[0]['origem'] ?? null);
    }

    public function test_webhook_keeps_cancelled_status_for_real_cancellation(): void
    {
        $document = $this->createFiscalDocument(DocumentModel::NFSE);
        $document->update([
            'status' => Status::CONFIRMED->value,
            'nfse_status' => NfeStatus::AUTHORIZED->value,
            'document_key' => 'WEBHOOK-NFSE-CANCELLED',
        ]);

        $response = $this->postJson(route('webhook.nfe'), [
            'chave' => 'WEBHOOK-NFSE-CANCELLED',
            'status' => 'cancelada',
        ]);

        $response->assertOk();

        $document->refresh();

        $this->assertSame(NfeStatus::CANCELED, $document->nfse_status);
        $this->assertSame(Status::CANCELLED, $document->status);
    }

    private function createFiscalDocument(DocumentModel $documentType): FiscalDocument
    {
        $company = Company::create([
            'name' => 'Empresa Webhook Fiscal',
            'document_number' => fake()->numerify('##############'),
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
        ]);

        $customer = Partner::create([
            'name' => 'Cliente Webhook Fiscal',
            'document_type' => 'CNPJ',
            'document_number' => fake()->numerify('##############'),
        ]);

        return FiscalDocument::create([
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'status' => Status::PENDING->value,
            'issued_at' => now()->toDateString(),
            'movement_at' => now()->toDateString(),
            'document_type' => $documentType->value,
            'nfe_status' => $documentType === DocumentModel::NFE ? NfeStatus::PENDING->value : null,
            'nfse_status' => $documentType === DocumentModel::NFSE ? NfeStatus::PENDING->value : null,
        ]);
    }
}
