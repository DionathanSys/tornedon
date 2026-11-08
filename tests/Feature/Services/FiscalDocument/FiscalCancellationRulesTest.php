<?php

namespace Tests\Feature\Services\FiscalDocument;

use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\NfeStatus;
use App\Enum\FiscalDocument\Status;
use App\Enum\Invoice\Status as InvoiceStatus;
use App\Models\Company;
use App\Models\FiscalDocument;
use App\Models\Invoice;
use App\Models\Partner;
use App\Models\User;
use App\Services\Fiscal\NfeConfigService;
use App\Services\FiscalDocument\Actions\CancelNfeAction;
use App\Services\FiscalDocument\FiscalAuthorizationTime;
use App\Services\FiscalDocument\FiscalCancellationPolicy;
use App\Services\FiscalDocument\FiscalDocumentIssueService;
use App\Services\Invoice\InvoiceFiscalDocumentEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class FiscalCancellationRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_nfe_uses_authorization_instant_and_includes_exact_24_hour_boundary(): void
    {
        $this->travelTo(now()->startOfSecond());
        [$document] = $this->document(['issued_at' => today()->subDays(3), 'authorized_at' => now()->subHours(24)]);
        $policy = app(FiscalCancellationPolicy::class);
        $this->assertTrue($policy->evaluate($document)['allowed']);
        $this->travel(1)->seconds();
        $this->assertFalse($policy->evaluate($document)['allowed']);
        $this->assertSame('cancellation_deadline_expired', $policy->evaluate($document)['reason']);
    }

    public function test_expired_cancellation_creates_pending_treatment_without_calling_provider_or_changing_invoice(): void
    {
        [$document, $owner] = $this->document(['authorized_at' => now()->subHours(25)]);
        $this->mock(NfeConfigService::class)->shouldNotReceive('buildSdkParams');
        $action = app(CancelNfeAction::class);
        $this->assertFalse($action->execute($document, 'Cancelamento solicitado pela empresa', $owner->id));
        $this->assertSame('cancellation_deadline_expired', $document->fiscalIssues()->sole()->reason);
        $this->assertSame(NfeStatus::AUTHORIZED, $document->fresh()->nfe_status);
        $this->assertSame(InvoiceStatus::CONFIRMED, $document->invoice->status);
        $this->assertFalse($document->invoice->canceled);
    }

    public function test_unknown_or_future_authorization_time_is_not_replaced_with_emission_date(): void
    {
        [$document] = $this->document(['authorized_at' => null]);
        $this->assertSame('unknown_authorization_time', app(FiscalCancellationPolicy::class)->evaluate($document)['reason']);
        $document->authorized_at = now()->addHour();
        $this->assertFalse(app(FiscalCancellationPolicy::class)->evaluate($document)['allowed']);
    }

    public function test_nfse_has_no_local_month_or_hour_limit(): void
    {
        [$document] = $this->document(['document_type' => DocumentModel::NFSE, 'nfe_status' => null, 'nfse_status' => NfeStatus::AUTHORIZED, 'issued_at' => today()->subMonths(4), 'authorized_at' => now()->subMonths(4)]);
        $this->assertTrue(app(FiscalCancellationPolicy::class)->evaluate($document)['allowed']);
        $this->assertNull(app(FiscalCancellationPolicy::class)->evaluate($document)['deadline']);
    }

    public function test_authorization_timestamp_is_recovered_from_protocol_and_not_renewed_by_later_response(): void
    {
        [$document] = $this->document(['authorized_at' => null]);
        $time = now()->subDays(3)->startOfSecond();
        $xml = '<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe"><protNFe><infProt><dhRecbto>'.$time->toIso8601String().'</dhRecbto></infProt></protNFe></nfeProc>';
        $resolver = app(FiscalAuthorizationTime::class);
        $this->assertTrue($time->equalTo($resolver->resolve($document, ['xml' => base64_encode($xml)])));
        $document->authorized_at = $time;
        $this->assertTrue($time->equalTo($resolver->resolve($document, ['data_hora_autorizacao' => now()->toIso8601String()])));
        $document->authorized_at = null;
        $this->assertNull($resolver->resolve($document, ['xml' => 'arquivo inválido']));
    }

    public function test_repeated_authorization_webhook_preserves_deadline_and_cancellation_webhook_resolves_issue(): void
    {
        [$document] = $this->document(['authorized_at' => now()->subDays(2)->startOfSecond()]);
        $time = $document->authorized_at;
        $issue = app(FiscalDocumentIssueService::class)->open($document, 'cancellation_deadline_expired', 'Prazo fiscal encerrado');
        $this->postJson(route('webhook.nfe'), ['chave' => $document->document_key, 'status' => 'autorizada'])->assertOk();
        $this->assertTrue($time->equalTo($document->fresh()->authorized_at));
        $this->assertSame('open', $issue->fresh()->status);
        $this->postJson(route('webhook.nfe'), ['chave' => $document->document_key, 'status' => 'cancelada'])->assertOk();
        $this->assertSame('resolved', $issue->fresh()->status);
        $this->assertNull($issue->fresh()->active_slot);
        $this->assertTrue($document->fresh()->isFiscalCancellationConfirmed());
        $this->assertTrue(app(InvoiceFiscalDocumentEligibilityService::class)->evaluate($document->invoice, DocumentModel::NFE)['allowed']);
    }

    public function test_external_report_keeps_fiscal_state_and_blocking_until_external_confirmation(): void
    {
        Storage::fake(config('attachments.default_disk', 'local'));
        [$document, $owner] = $this->document(['authorized_at' => now()->subDays(2)]);
        $issues = app(FiscalDocumentIssueService::class);
        $issues->open($document, 'cancellation_deadline_expired', 'Prazo fiscal encerrado');
        $issue = $issues->manage($document, $owner, [
            'resolution' => 'external_cancellation_reported', 'notes' => 'Solicitação protocolada junto ao órgão emissor.', 'reference' => 'PROC-123',
        ], UploadedFile::fake()->create('comprovante.pdf', 2, 'application/pdf'));
        $this->assertSame('awaiting_confirmation', $issue->status);
        $this->assertNotNull($issue->evidence_attachment_id);
        $this->assertSame($document->id, $issue->evidenceAttachment->attachable_id);
        $this->assertSame(NfeStatus::AUTHORIZED, $document->fresh()->nfe_status);
        $this->assertFalse(app(InvoiceFiscalDocumentEligibilityService::class)->evaluate($document->invoice, DocumentModel::NFE)['allowed']);
        $issues->manage($document, $owner, ['resolution' => 'follow_up', 'notes' => 'Aguardando retorno definitivo do órgão emissor.']);
        $this->assertSame('awaiting_confirmation', $issue->fresh()->status);
        $this->expectException(ValidationException::class);
        $issues->manage($document, $owner, ['resolution' => 'keep_document', 'notes' => 'Tentativa de encerrar sem consulta definitiva.']);
    }

    public function test_recording_external_cancellation_requires_reference_and_evidence(): void
    {
        [$document, $owner] = $this->document(['authorized_at' => now()->subDays(2)]);
        $this->expectException(ValidationException::class);
        app(FiscalDocumentIssueService::class)->manage($document, $owner, ['resolution' => 'external_cancellation_reported', 'notes' => 'Procedimento administrativo de cancelamento.']);
    }

    public function test_admin_can_keep_document_but_this_does_not_release_new_note_generation(): void
    {
        [$document, $owner] = $this->document(['authorized_at' => now()->subDays(2)]);
        $issue = app(FiscalDocumentIssueService::class)->manage($document, $owner, ['resolution' => 'keep_document', 'notes' => 'A empresa decidiu manter a nota original autorizada.']);
        $this->assertSame('resolved', $issue->status);
        $this->assertSame(NfeStatus::AUTHORIZED, $document->fresh()->nfe_status);
        $this->assertFalse(app(InvoiceFiscalDocumentEligibilityService::class)->evaluate($document->invoice, DocumentModel::NFE)['allowed']);
    }

    public function test_ordinary_member_and_platform_admin_without_membership_cannot_manage_fiscal_issue(): void
    {
        [$document] = $this->document(['authorized_at' => now()->subDays(2)]);
        $member = User::factory()->create();
        $member->companies()->attach($document->company_id, ['role' => 'member', 'is_active' => true]);
        $platformAdmin = User::factory()->create(['is_admin' => true]);
        $issues = app(FiscalDocumentIssueService::class);
        $this->assertFalse($issues->canManage($member, $document));
        $this->assertFalse($issues->canManage($platformAdmin, $document));
        $this->expectException(HttpException::class);
        $issues->manage($document, $member, ['resolution' => 'keep_document', 'notes' => 'Tentativa de usuário sem permissão administrativa.']);
    }

    public function test_same_invoice_relationship_is_sufficient_and_wrong_local_cancellation_does_not_release_generation(): void
    {
        [$document] = $this->document();
        $document->update(['status' => Status::CANCELLED]);
        $rules = app(InvoiceFiscalDocumentEligibilityService::class);
        $this->assertFalse($rules->evaluate($document->invoice, DocumentModel::NFE)['allowed']);
        $document->update(['nfe_status' => NfeStatus::CANCELED]);
        $this->assertTrue($rules->evaluate($document->invoice, DocumentModel::NFE)['allowed']);
    }

    private function document(array $overrides = []): array
    {
        $owner = User::factory()->create();
        $company = Company::factory()->create();
        $owner->companies()->attach($company, ['role' => 'owner', 'is_active' => true]);
        $customer = Partner::factory()->create();
        $invoice = Invoice::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'invoice_number' => 'FISCAL-001', 'invoice_date' => today(), 'status' => InvoiceStatus::CONFIRMED, 'created_by' => $owner->id]);
        $document = FiscalDocument::create([
            'company_id' => $company->id, 'customer_id' => $customer->id, 'invoice_id' => $invoice->id,
            'document_type' => DocumentModel::NFE, 'operation_type' => '1', 'status' => Status::CONFIRMED,
            'issued_at' => today(), 'movement_at' => today(), 'document_key' => fake()->numerify('############################################'),
            'nfe_status' => NfeStatus::AUTHORIZED, 'authorized_at' => now(), 'created_by' => $owner->id, ...$overrides,
        ]);

        return [$document, $owner];
    }
}
