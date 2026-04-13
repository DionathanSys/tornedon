<?php

namespace Tests\Feature\Services\Invoice;

use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\Status as FiscalDocumentStatus;
use App\Enum\Invoice\Status as InvoiceStatus;
use App\Enum\Requisition\Status as RequisitionStatus;
use App\Enum\ServiceOrder\Priority as ServiceOrderPriority;
use App\Enum\ServiceOrder\State as ServiceOrderState;
use App\Enum\ServiceOrder\Type as ServiceOrderType;
use App\Models\Company;
use App\Models\CompanyPartner;
use App\Models\CompanyPreference;
use App\Models\Contact;
use App\Models\FiscalDocument;
use App\Models\Invoice;
use App\Models\Partner;
use App\Models\Requisition;
use App\Models\ServiceOrder;
use App\Services\Email\Contracts\EmailProviderInterface;
use App\Services\Email\DTO\EmailMessage;
use App\Services\FiscalDocument\NfeDocumentService;
use App\Services\Invoice\Actions\PrintInvoicePdfAction;
use App\Services\Invoice\Actions\SendInvoiceEmailAction;
use App\Services\Requisition\Actions\PrintRequisitionPdfAction;
use App\Services\ServiceOrder\Actions\PrintServiceOrderPdfAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SendInvoiceEmailActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sends_invoice_email_with_all_expected_attachments(): void
    {
        [$invoice, $companyPartner] = $this->createInvoiceContext(withServiceOrder: true, withRequisition: true);

        CompanyPreference::set('email_cc', 'financeiro@example.com', $invoice->company_id);

        $this->mockInvoicePdf('invoice-pdf');
        $this->mockFiscalDanfe(['danfe-content']);
        $this->mockServiceOrderPdf('service-order-pdf');
        $this->mockRequisitionPdf('requisition-pdf');

        $provider = Mockery::mock(EmailProviderInterface::class);
        $provider->shouldReceive('send')
            ->once()
            ->withArgs(function (EmailMessage $message): bool {
                $filenames = collect($message->attachments)->map(fn ($attachment) => $attachment->filename)->all();

                $this->assertSame(['cliente@example.com'], $message->to);
                $this->assertSame(['financeiro@example.com'], $message->cc);
                $this->assertSame('Assunto manual', $message->subject);
                $this->assertSame('Mensagem manual', $message->text);
                $this->assertCount(5, $message->attachments);
                $this->assertSame([
                    'fatura-INV-001.pdf',
                    'danfe-1001.pdf',
                    'nf-1001.xml',
                    'ordem-servico-SO-001.pdf',
                    'requisicao-REQ-001.pdf',
                ], $filenames);

                return true;
            })
            ->andReturn([
                'provider_message_id' => 'msg-123',
                'provider_payload' => ['id' => 'msg-123'],
            ]);

        $this->app->instance(EmailProviderInterface::class, $provider);

        $action = app(SendInvoiceEmailAction::class);
        $result = $action->execute($invoice->fresh(), 'Assunto manual', 'Mensagem manual', 10);

        $this->assertTrue($result, $action->getMessage());
        $this->assertTrue($action->isSuccess());
        $this->assertNotNull($companyPartner);
    }

    public function test_it_sends_all_fiscal_documents_when_invoice_has_multiple_documents(): void
    {
        [$invoice] = $this->createInvoiceContext(withServiceOrder: false, withRequisition: false);

        FiscalDocument::query()->create([
            'customer_id' => $invoice->customer_id,
            'company_id' => $invoice->company_id,
            'invoice_id' => $invoice->id,
            'status' => FiscalDocumentStatus::PENDING->value,
            'issued_at' => now()->toDateString(),
            'movement_at' => now()->toDateString(),
            'document_type' => DocumentModel::NFE->value,
            'document_number' => '1002',
            'nfe_payload' => ['xml' => '<xml>doc-2</xml>'],
            'pending' => true,
            'confirmed' => false,
            'canceled' => false,
        ]);

        $this->mockInvoicePdf('invoice-pdf');
        $this->mockFiscalDanfe(['danfe-1', 'danfe-2']);

        $provider = Mockery::mock(EmailProviderInterface::class);
        $provider->shouldReceive('send')
            ->once()
            ->withArgs(function (EmailMessage $message): bool {
                $filenames = collect($message->attachments)->map(fn ($attachment) => $attachment->filename)->all();

                $this->assertSame([
                    'fatura-INV-001.pdf',
                    'danfe-1001.pdf',
                    'nf-1001.xml',
                    'danfe-1002.pdf',
                    'nf-1002.xml',
                ], $filenames);

                return true;
            })
            ->andReturn([
                'provider_message_id' => 'msg-456',
                'provider_payload' => ['id' => 'msg-456'],
            ]);

        $this->app->instance(EmailProviderInterface::class, $provider);

        $action = app(SendInvoiceEmailAction::class);

        $this->assertTrue($action->execute($invoice->fresh(), 'Assunto', 'Mensagem', 10), $action->getMessage());
    }

    public function test_it_sends_only_invoice_and_fiscal_attachments_when_no_service_order_or_requisition_exist(): void
    {
        [$invoice] = $this->createInvoiceContext(withServiceOrder: false, withRequisition: false);

        $this->mockInvoicePdf('invoice-pdf');
        $this->mockFiscalDanfe(['danfe-content']);

        $provider = Mockery::mock(EmailProviderInterface::class);
        $provider->shouldReceive('send')
            ->once()
            ->withArgs(function (EmailMessage $message): bool {
                $filenames = collect($message->attachments)->map(fn ($attachment) => $attachment->filename)->all();

                $this->assertSame([
                    'fatura-INV-001.pdf',
                    'danfe-1001.pdf',
                    'nf-1001.xml',
                ], $filenames);

                return true;
            })
            ->andReturn([
                'provider_message_id' => 'msg-789',
                'provider_payload' => ['id' => 'msg-789'],
            ]);

        $this->app->instance(EmailProviderInterface::class, $provider);

        $action = app(SendInvoiceEmailAction::class);

        $this->assertTrue($action->execute($invoice->fresh(), 'Assunto', 'Mensagem', 10), $action->getMessage());
    }

    public function test_it_fails_when_company_partner_is_missing(): void
    {
        [$invoice] = $this->createInvoiceContext(createCompanyPartner: false);

        $action = app(SendInvoiceEmailAction::class);

        $this->assertFalse($action->execute($invoice->fresh(), 'Assunto', 'Mensagem', 10));
        $this->assertSame('Nenhum vínculo ativo entre empresa e cliente foi encontrado para o envio.', $action->getMessage());
    }

    public function test_it_fails_when_no_recipient_is_available(): void
    {
        [$invoice, $companyPartner] = $this->createInvoiceContext(createContact: false);

        $companyPartner->update([
            'email_to_override' => null,
            'email_cc_override' => null,
            'email_bcc_override' => null,
        ]);

        $action = app(SendInvoiceEmailAction::class);

        $this->assertFalse($action->execute($invoice->fresh(), 'Assunto', 'Mensagem', 10));
        $this->assertSame('Nenhum destinatário válido foi encontrado para o cliente desta fatura.', $action->getMessage());
    }

    public function test_it_fails_when_invoice_has_no_fiscal_document(): void
    {
        [$invoice] = $this->createInvoiceContext(withFiscalDocument: false);

        $action = app(SendInvoiceEmailAction::class);

        $this->assertFalse($action->execute($invoice->fresh(), 'Assunto', 'Mensagem', 10));
        $this->assertSame('A fatura não possui documento fiscal vinculado para envio.', $action->getMessage());
    }

    public function test_it_fails_when_a_required_attachment_cannot_be_generated(): void
    {
        [$invoice] = $this->createInvoiceContext(withServiceOrder: false, withRequisition: false);

        $invoicePdfAction = Mockery::mock(PrintInvoicePdfAction::class);
        $invoicePdfAction->shouldReceive('execute')->once()->andReturn(null);
        $invoicePdfAction->shouldReceive('hasError')->once()->andReturn(true);
        $this->app->instance(PrintInvoicePdfAction::class, $invoicePdfAction);

        $provider = Mockery::mock(EmailProviderInterface::class);
        $provider->shouldReceive('send')->never();
        $this->app->instance(EmailProviderInterface::class, $provider);

        $action = app(SendInvoiceEmailAction::class);

        $this->assertFalse($action->execute($invoice->fresh(), 'Assunto', 'Mensagem', 10));
        $this->assertStringContainsString('Não foi possível gerar o PDF da fatura.', (string) $action->getMessage());
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * @return array{Invoice,CompanyPartner|null}
     */
    private function createInvoiceContext(
        bool $withFiscalDocument = true,
        bool $withServiceOrder = false,
        bool $withRequisition = false,
        bool $createCompanyPartner = true,
        bool $createContact = true,
    ): array {
        $owner = \App\Models\User::factory()->create();

        $company = Company::query()->create([
            'name' => 'Empresa Teste ' . str()->uuid(),
            'document_number' => '12345678000199',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'email' => 'empresa@example.com',
            'is_active' => true,
            'created_by' => $owner->id,
        ]);

        $customer = Partner::query()->create([
            'name' => 'Cliente Teste ' . str()->uuid(),
            'document_type' => 'CPF',
            'document_number' => preg_replace('/\D/', '', (string) fake()->unique()->cpf(false)),
            'created_by' => $owner->id,
        ]);

        $invoice = Invoice::query()->create([
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'invoice_number' => 'INV-001',
            'invoice_date' => now()->toDateString(),
            'status' => InvoiceStatus::PENDING->value,
            'pending' => true,
            'confirmed' => false,
            'canceled' => false,
        ]);

        $companyPartner = null;

        if ($createCompanyPartner) {
            $companyPartner = CompanyPartner::query()->create([
                'company_id' => $company->id,
                'partner_id' => $customer->id,
                'type' => ['customer'],
                'invoice_threshold' => 0,
                'is_active' => true,
                'email_bcc_override' => 'auditoria@example.com',
            ]);

            if ($createContact) {
                Contact::query()->create([
                    'company_partner_id' => $companyPartner->id,
                    'email' => 'cliente@example.com',
                    'notify' => true,
                    'is_active' => true,
                ]);
            }
        }

        if ($withFiscalDocument) {
            FiscalDocument::query()->create([
                'customer_id' => $customer->id,
                'company_id' => $company->id,
                'invoice_id' => $invoice->id,
                'status' => FiscalDocumentStatus::PENDING->value,
                'issued_at' => now()->toDateString(),
                'movement_at' => now()->toDateString(),
                'document_type' => DocumentModel::NFE->value,
                'document_number' => '1001',
                'nfe_payload' => ['xml' => '<xml>doc-1</xml>'],
                'pending' => true,
                'confirmed' => false,
                'canceled' => false,
            ]);
        }

        if ($withServiceOrder) {
            ServiceOrder::query()->create([
                'number' => 'SO-001',
                'customer_id' => $customer->id,
                'company_id' => $company->id,
                'order_date' => now()->toDateString(),
                'status' => ServiceOrderState::OPEN->value,
                'priority' => ServiceOrderPriority::NORMAL->value,
                'type' => ServiceOrderType::MAINTENANCE->value,
                'invoice_id' => $invoice->id,
            ]);
        }

        if ($withRequisition) {
            Requisition::query()->create([
                'number' => 'REQ-001',
                'customer_id' => $customer->id,
                'company_id' => $company->id,
                'sale_date' => now()->toDateString(),
                'status' => RequisitionStatus::OPEN->value,
                'invoice_id' => $invoice->id,
                'stock_consumed' => true,
            ]);
        }

        return [$invoice, $companyPartner];
    }

    private function mockInvoicePdf(string $content): void
    {
        $invoicePdfAction = Mockery::mock(PrintInvoicePdfAction::class);
        $invoicePdfAction->shouldReceive('execute')->andReturn(base64_encode($content));
        $invoicePdfAction->shouldReceive('hasError')->andReturn(false);
        $this->app->instance(PrintInvoicePdfAction::class, $invoicePdfAction);
    }

    /**
     * @param array<int,string> $contents
     */
    private function mockFiscalDanfe(array $contents): void
    {
        $mock = Mockery::mock(NfeDocumentService::class);

        foreach ($contents as $content) {
            $mock->shouldReceive('danfe')->once()->andReturn(base64_encode($content));
        }

        $this->app->instance(NfeDocumentService::class, $mock);
    }

    private function mockServiceOrderPdf(string $content): void
    {
        $serviceOrderPdfAction = Mockery::mock(PrintServiceOrderPdfAction::class);
        $serviceOrderPdfAction->shouldReceive('execute')->andReturn(base64_encode($content));
        $serviceOrderPdfAction->shouldReceive('hasError')->andReturn(false);
        $this->app->instance(PrintServiceOrderPdfAction::class, $serviceOrderPdfAction);
    }

    private function mockRequisitionPdf(string $content): void
    {
        $requisitionPdfAction = Mockery::mock(PrintRequisitionPdfAction::class);
        $requisitionPdfAction->shouldReceive('execute')->andReturn(base64_encode($content));
        $requisitionPdfAction->shouldReceive('hasError')->andReturn(false);
        $this->app->instance(PrintRequisitionPdfAction::class, $requisitionPdfAction);
    }
}
