<?php

namespace Tests\Feature\Commitments;

use App\Enum\Commitment\BillingStatus;
use App\Enum\FiscalDocument\NfeStatus;
use App\Enum\Payment\Condition;
use App\Enum\Payment\Method;
use App\Jobs\ProcessCommitmentBillingBatchJob;
use App\Models\CommitmentBilling;
use App\Models\CommitmentSetting;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Requisition;
use App\Models\User;
use App\Services\Commitments\ApproveCommitmentMatchService;
use App\Services\Commitments\ProcessCommitmentBillingBatchService;
use App\Services\Commitments\RequestCommitmentBillingService;
use App\Services\FiscalDocument\NfseDocumentService;
use App\Services\Invoice\InvoiceService;
use App\Services\ServiceOrder\InvoiceServiceOrderWorkflow;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\CreatesCommitmentReviewFixtures;
use Tests\TestCase;

class CommitmentBillingTest extends TestCase
{
    use CreatesCommitmentReviewFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createReviewContext();
        $this->actingAs($this->user);
        Queue::fake();
        CommitmentSetting::query()->update(['payment_method' => Method::BANK_TRANSFER->value, 'payment_condition' => Condition::CASH->value, 'nfse_footer_message' => 'Pagamento conforme contrato.']);
    }

    private function approved(): CommitmentBilling
    {
        $record = $this->commitment();
        $order = $this->order();

        return app(ApproveCommitmentMatchService::class)->approve($this->company->id, $record->id, $order->id, $this->user->id);
    }

    private function request(array $billings, bool $emit = false)
    {
        return app(RequestCommitmentBillingService::class)->request($this->company->id, array_map(fn ($billing) => $billing->customer_commitment_id, $billings), $this->user->id, $emit);
    }

    private function process($batch): void
    {
        app(ProcessCommitmentBillingBatchService::class)->process($batch->id, $batch->company_id, $batch->run_token);
    }

    public function test_creates_one_invoice_nfse_and_receivable_with_exact_texts_without_emitting(): void
    {
        $billing = $this->approved();
        $this->mock(NfseDocumentService::class)->shouldNotReceive('emitir');
        $batch = $this->request([$billing]);
        Queue::assertPushed(ProcessCommitmentBillingBatchJob::class);
        $this->assertDatabaseCount('invoices', 0);
        $this->process($batch);
        $billing->refresh();
        $this->assertSame(BillingStatus::CONFIRMED, $billing->status, $billing->last_error ?? '');
        $this->assertSame('completed', $batch->fresh()->status);
        $this->assertSame(1, $billing->attempts);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('fiscal_documents', 1);
        $this->assertDatabaseCount('account_receivables', 1);
        $document = $billing->invoice->fiscalDocuments()->sole();
        $this->assertSame('nfse', $document->document_type->value);
        $this->assertSame($billing->serviceOrder->items->first()->service->name, $document->items()->sole()->description);
        $this->assertSame("Pagamento conforme contrato.\nPatrimônio: 142186\nEmpenho: {$billing->commitment->number}/2026", $document->items()->sole()->additional_information);
        $this->assertSame(0, $billing->invoice->requisitions()->count());
        $this->assertSame('faturada', $billing->serviceOrder->fresh()->status->value);
    }

    public function test_optional_emission_reuses_existing_service_after_financial_commit(): void
    {
        $billing = $this->approved();
        $this->mock(NfseDocumentService::class)->shouldReceive('emitir')->once()->andReturnUsing(function ($document, $userId) {
            $this->assertTrue($document->invoice->confirmed);
            $this->assertSame($this->user->id, $userId);
            $document->update(['nfse_status' => NfeStatus::QUEUED]);

            return true;
        });
        $batch = $this->request([$billing], true);
        $this->process($batch);
        $this->assertSame(BillingStatus::EMISSION_QUEUED, $billing->fresh()->status, $billing->fresh()->last_error ?? '');
        $this->assertSame('completed', $batch->fresh()->status);
    }

    public function test_emission_failure_preserves_confirmation_and_retry_reuses_invoice_and_document(): void
    {
        $billing = $this->approved();
        $emitter = $this->mock(NfseDocumentService::class);
        $emitter->shouldReceive('emitir')->once()->andReturn(false);
        $emitter->shouldReceive('getMessageUser')->andReturn('Dados fiscais incompletos.');
        $batch = $this->request([$billing], true);
        $this->process($batch);
        $billing->refresh();
        $this->assertSame(BillingStatus::CONFIRMED, $billing->status);
        $this->assertSame('Dados fiscais incompletos.', $billing->last_error);
        $this->assertSame('failed', $batch->fresh()->status);
        $invoiceId = $billing->invoice_id;
        $documentId = $billing->invoice->fiscalDocuments()->sole()->id;
        $emitter = $this->mock(NfseDocumentService::class);
        $emitter->shouldReceive('emitir')->once()->andReturn(true);
        $retry = $this->request([$billing], true);
        $this->process($retry);
        $this->assertSame(BillingStatus::EMISSION_QUEUED, $billing->fresh()->status, $billing->fresh()->last_error ?? '');
        $this->assertSame($invoiceId, $billing->fresh()->invoice_id);
        $this->assertSame($documentId, $billing->fresh()->invoice->fiscalDocuments()->sole()->id);
        $this->assertDatabaseCount('account_receivables', 1);
    }

    public function test_confirmation_failure_keeps_invoice_and_resumes_confirmation_without_rebilling(): void
    {
        $billing = $this->approved();
        $invoiceService = $this->partialMock(InvoiceService::class);
        $invoiceService->shouldReceive('confirm')->once()->andReturn(null);
        $invoiceService->shouldReceive('getMessageUser')->andReturn('Falha de confirmação.');
        $batch = $this->request([$billing]);
        $this->process($batch);
        $billing->refresh();
        $this->assertSame(BillingStatus::FAILED, $billing->status);
        $this->assertSame(BillingStatus::INVOICED, $billing->completed_step);
        $this->assertNotNull($billing->invoice_id);
        $this->assertDatabaseCount('fiscal_documents', 0);
        $invoiceId = $billing->invoice_id;
        $this->app->forgetInstance(InvoiceService::class);
        $retry = $this->request([$billing]);
        $this->process($retry);
        $this->assertSame(BillingStatus::CONFIRMED, $billing->fresh()->status, $billing->fresh()->last_error ?? '');
        $this->assertSame($invoiceId, $billing->fresh()->invoice_id);
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_job_redelivery_is_noop_and_active_selection_cannot_be_enqueued_twice(): void
    {
        $billing = $this->approved();
        $batch = $this->request([$billing]);
        try {
            $this->request([$billing]);
            $this->fail('Duplicated active selection accepted.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('commitment_billing_batches', 1);
        }
        $this->process($batch);
        $this->process($batch);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertSame(1, $billing->fresh()->attempts);
    }

    public function test_batch_isolates_changed_values_and_preserves_settings_snapshot(): void
    {
        $first = $this->approved();
        $second = $this->approved();
        $batch = $this->request([$first, $second]);
        CommitmentSetting::query()->update(['nfse_footer_message' => 'New settings after request']);
        $first->serviceOrder->items()->first()->update(['unit_price' => 500]);
        $this->process($batch);
        $this->assertSame('partial_failed', $batch->fresh()->status);
        $this->assertSame(BillingStatus::FAILED, $first->fresh()->status);
        $this->assertNull($first->fresh()->invoice_id);
        $this->assertSame(BillingStatus::CONFIRMED, $second->fresh()->status, $second->fresh()->last_error ?? '');
        $this->assertStringContainsString('Pagamento conforme contrato.', $second->fresh()->settings_snapshot['nfse_additional_information']);
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_worker_rechecks_access_and_tenant_scope(): void
    {
        $billing = $this->approved();
        $batch = $this->request([$billing]);
        $this->user->companies()->detach($this->company);
        $this->process($batch);
        $this->assertSame('failed', $batch->fresh()->status);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_interrupted_batch_can_resume_without_recreating_existing_invoice(): void
    {
        $billing = $this->approved();
        $batch = $this->request([$billing]);
        app(ProcessCommitmentBillingBatchService::class)->fail($batch->id, $batch->company_id, $batch->run_token, 'Worker interrompido.');
        $this->assertFalse($billing->fresh()->isRunning());
        $retry = $this->request([$billing]);
        $this->process($batch);
        $this->process($retry);
        $this->assertSame(BillingStatus::CONFIRMED, $billing->fresh()->status, $billing->fresh()->last_error ?? '');
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_requisition_blocks_request_and_is_rechecked_if_attached_after_queueing(): void
    {
        $first = $this->approved();
        $second = $this->approved();
        $requestAttributes = ['company_id' => $this->company->id, 'customer_id' => $this->customer->id, 'sale_date' => '2026-01-01', 'status' => 'closed', 'stock_consumed' => false];
        Requisition::create([...$requestAttributes, 'number' => 'R-1', 'service_order_id' => $first->service_order_id]);
        $batch = $this->request([$first, $second]);
        Requisition::create([...$requestAttributes, 'number' => 'R-2', 'service_order_id' => $second->service_order_id]);
        $this->process($batch);
        $this->assertSame('failed', $batch->fresh()->status);
        foreach ([$first, $second] as $billing) {
            $this->assertStringContainsString('requisição', $billing->fresh()->last_error);
            $this->assertNull($billing->fresh()->invoice_id);
        }
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('fiscal_documents', 0);
    }

    public function test_false_workflow_return_rolls_back_even_if_it_created_an_invoice(): void
    {
        $billing = $this->approved();
        $workflow = $this->mock(InvoiceServiceOrderWorkflow::class);
        $workflow->shouldReceive('execute')->once()->andReturnUsing(function () {
            Invoice::create(['company_id' => $this->company->id, 'customer_id' => $this->customer->id, 'invoice_number' => 'ROLLBACK-1', 'invoice_date' => now(), 'status' => 'pending', 'created_by' => $this->user->id]);

            return false;
        });
        $workflow->shouldReceive('getMessageUser')->andReturn('Falha transacional.');
        $batch = $this->request([$billing]);
        $this->process($batch);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertNull($billing->fresh()->invoice_id);
        $this->assertSame('Falha transacional.', $billing->fresh()->last_error);
    }

    public function test_tenant_and_permission_checks_reject_request_atomically(): void
    {
        $billing = $this->approved();
        foreach ([[Company::factory()->create()->id, $this->user->id], [$this->company->id, User::factory()->create()->id]] as [$companyId, $userId]) {
            try {
                app(RequestCommitmentBillingService::class)->request($companyId, [$billing->customer_commitment_id], $userId);
                $this->fail('Unauthorized request accepted.');
            } catch (ModelNotFoundException|HttpException) {
                $this->assertDatabaseCount('commitment_billing_batches', 0);
            }
        }
    }

    public function test_stale_job_cannot_overwrite_replacement_attempt(): void
    {
        $billing = $this->approved();
        $old = $this->request([$billing]);
        $old->items()->update(['updated_at' => now()->subMinutes(11)]);
        $retry = $this->request([$billing]);
        $this->process($old);
        $this->assertDatabaseCount('invoices', 0);
        $this->process($retry);
        $this->assertSame(BillingStatus::CONFIRMED, $billing->fresh()->status, $billing->fresh()->last_error ?? '');
        $this->assertSame($retry->id, $billing->fresh()->active_batch_id);
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_queue_prevents_unlink_and_failed_attempt_before_invoice_can_be_unlinked_preserving_history(): void
    {
        $billing = $this->approved();
        $batch = $this->request([$billing]);
        try {
            app(ApproveCommitmentMatchService::class)->unlink($this->company->id, $billing->customer_commitment_id, $this->user->id, 'Escolher outra OS');
            $this->fail('Active batch allowed unlink.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('commitment_billings', 1);
        }
        app(ProcessCommitmentBillingBatchService::class)->fail($batch->id, $batch->company_id, $batch->run_token, 'Falha da fila.');
        app(ApproveCommitmentMatchService::class)->unlink($this->company->id, $billing->customer_commitment_id, $this->user->id, 'Escolher outra OS');
        $this->assertDatabaseCount('commitment_billings', 0);
        $this->assertSame($billing->customer_commitment_id, $batch->items()->sole()->customer_commitment_id);
        $this->assertNull($batch->items()->sole()->commitment_billing_id);
        $this->process($batch);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_rps_reconciliation_blocks_emission_without_undoing_financial_confirmation(): void
    {
        $billing = $this->approved();
        $batch = $this->request([$billing]);
        $this->process($batch);
        $document = $billing->fresh()->invoice->fiscalDocuments()->sole();
        $document->update(['nfse_status' => NfeStatus::RPS_RECONCILIATION_PENDING]);
        $this->mock(NfseDocumentService::class)->shouldNotReceive('emitir');
        $retry = $this->request([$billing], true);
        $this->process($retry);
        $this->assertSame(BillingStatus::CONFIRMED, $billing->fresh()->status);
        $this->assertStringContainsString('Revise a situação fiscal', $billing->fresh()->last_error);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('fiscal_documents', 1);
        $this->assertDatabaseCount('account_receivables', 1);
    }

    public function test_missing_settings_or_oversized_footer_do_not_create_invoice(): void
    {
        foreach ([['payment_method' => null], ['payment_method' => Method::BANK_TRANSFER->value, 'nfse_footer_message' => str_repeat('x', 2000)], ['nfse_footer_message' => str_repeat('á', 1000)]] as $settings) {
            $billing = $this->approved();
            CommitmentSetting::query()->update($settings);
            $batch = $this->request([$billing]);
            $this->process($batch);
            $this->assertSame(BillingStatus::FAILED, $billing->fresh()->status);
            $this->assertNotEmpty($billing->fresh()->last_error);
        }
        $this->assertDatabaseCount('invoices', 0);
    }
}
