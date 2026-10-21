<?php

namespace Tests\Feature\Commitments;

use App\Enum\Commitment\BillingStatus;
use App\Enum\Commitment\ImportType;
use App\Enum\Commitment\MatchStatus;
use App\Enum\FiscalDocument\NfeStatus;
use App\Enum\Payment\Condition;
use App\Enum\Payment\Method;
use App\Filament\Clusters\Financial\Resources\CustomerCommitments\CustomerCommitmentResource;
use App\Filament\Clusters\Financial\Resources\CustomerCommitments\Pages\ListCustomerCommitments;
use App\Filament\Clusters\Financial\Resources\CustomerCommitments\Pages\ViewCustomerCommitment;
use App\Jobs\ProcessCommitmentBillingBatchJob;
use App\Jobs\ProcessCommitmentImportFileJob;
use App\Models\CommitmentBillingBatch;
use App\Models\CommitmentSetting;
use App\Models\Company;
use App\Models\CustomerCommitment;
use App\Services\Commitments\ApproveCommitmentMatchService;
use App\Services\Commitments\DTO\ExtractedPdf;
use App\Services\Commitments\ImportCommitmentFilesService;
use App\Services\Commitments\Pdf\PdfTextExtractor;
use App\Services\Commitments\ProcessCommitmentBillingBatchService;
use App\Services\Commitments\ProcessCommitmentImportFileService;
use App\Services\Commitments\RequestCommitmentBillingService;
use App\Services\FiscalDocument\NfseDocumentService;
use Filament\Actions\ActionGroup;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Enums\RecordActionsPosition;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\CreatesCommitmentReviewFixtures;
use Tests\TestCase;

class CommitmentReviewFilamentTest extends TestCase
{
    use CreatesCommitmentReviewFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createReviewContext();
        Queue::fake();
        Storage::fake('local');
        $this->actingAs($this->user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->company);
    }

    public function test_table_billing_modal_previews_texts_and_enqueues_only_after_confirmation(): void
    {
        CommitmentSetting::query()->update(['payment_method' => Method::BANK_TRANSFER->value, 'payment_condition' => Condition::DAYS_30->value, 'nfse_footer_message' => 'Contrato municipal.']);
        $record = $this->commitment();
        $order = $this->order();
        app(ApproveCommitmentMatchService::class)->approve($this->company->id, $record->id, $order->id, $this->user->id);
        $page = Livewire::test(ListCustomerCommitments::class)->mountAction(TestAction::make('bill_commitment')->table($record))
            ->assertActionMounted(TestAction::make('bill_commitment')->table($record));
        $instance = $page->instance();
        $entries = collect($instance->getSchema($instance->getMountedActionSchemaName())->getFlatComponents());
        $preview = $entries->first(fn ($entry) => $entry instanceof RepeatableEntry && $entry->getName() === 'billing_preview');
        $this->assertNotNull($preview);
        $this->assertStringContainsString('Contrato municipal.', $preview->getState()[0]['footer']);
        $this->assertStringContainsString('Patrimônio: 142186', $preview->getState()[0]['footer']);
        $this->assertSame($order->items->first()->service->name, $preview->getState()[0]['body']);
        Queue::assertNotPushed(ProcessCommitmentBillingBatchJob::class);
        $this->assertDatabaseCount('invoices', 0);
        $page->callMountedAction(['emit_nfse' => false])->assertHasNoActionErrors()->assertNoRedirect();
        Queue::assertPushed(ProcessCommitmentBillingBatchJob::class);
        $batch = CommitmentBillingBatch::sole();
        $this->assertFalse($batch->emit_nfse);
        $this->assertSame($record->billing->id, $batch->items()->sole()->commitment_billing_id);
        app(ProcessCommitmentBillingBatchService::class)->process($batch->id, $batch->company_id, $batch->run_token);
        Livewire::test(ListCustomerCommitments::class)->assertSee('Fatura confirmada')->assertSee('Concluído')->filterTable('billing_batch', $batch->id)->assertCanSeeTableRecords([$record]);
        Livewire::test(ViewCustomerCommitment::class, ['record' => $record->id])->assertSuccessful()->assertSee('Faturamento e emissão')->assertSee('Contrato municipal.');
    }

    public function test_bulk_billing_skips_unapproved_rows_and_persists_optional_emission(): void
    {
        CommitmentSetting::query()->update(['payment_method' => Method::BANK_TRANSFER->value, 'payment_condition' => Condition::DAYS_30->value]);
        $record = $this->commitment();
        $unapproved = $this->commitment();
        $order = $this->order();
        app(ApproveCommitmentMatchService::class)->approve($this->company->id, $record->id, $order->id, $this->user->id);
        Livewire::test(ListCustomerCommitments::class)->selectTableRecords([$record->id, $unapproved->id])
            ->callAction(TestAction::make('bill_selected')->table()->bulk(), ['emit_nfse' => true])->assertHasNoActionErrors();
        $batch = CommitmentBillingBatch::sole();
        $this->assertTrue($batch->emit_nfse);
        $this->assertSame(1, $batch->items()->count());
        $this->assertNull($unapproved->fresh()->billing);
        Queue::assertPushed(ProcessCommitmentBillingBatchJob::class);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_bulk_billing_modal_shows_selected_commitments_total_and_fiscal_previews_before_submission(): void
    {
        CommitmentSetting::query()->update(['payment_method' => Method::BANK_TRANSFER->value, 'payment_condition' => Condition::DAYS_30->value, 'nfse_footer_message' => 'Contrato municipal.']);
        $first = $this->commitment();
        $firstOrder = $this->order();
        $second = $this->commitment(['amount' => 540, 'net_amount' => 540]);
        $secondOrder = $this->order(540);
        $unselected = $this->commitment();
        foreach ([[$first, $firstOrder], [$second, $secondOrder]] as [$record, $order]) {
            app(ApproveCommitmentMatchService::class)->approve($this->company->id, $record->id, $order->id, $this->user->id);
        }
        $page = Livewire::test(ListCustomerCommitments::class)->selectTableRecords([$first->id, $second->id])
            ->mountAction(TestAction::make('bill_selected')->table()->bulk())->assertActionMounted(TestAction::make('bill_selected')->table()->bulk());
        $instance = $page->instance();
        $entries = collect($instance->getSchema($instance->getMountedActionSchemaName())->getFlatComponents());
        $summary = $entries->first(fn ($entry) => $entry instanceof TextEntry && $entry->getName() === 'selected_summary');
        $preview = $entries->first(fn ($entry) => $entry instanceof RepeatableEntry && $entry->getName() === 'billing_preview');
        $this->assertSame('2 empenho(s) — R$ 940,00', $summary->getState());
        $rows = collect($preview->getState())->keyBy('commitment');
        $this->assertCount(2, $rows);
        foreach ([[$first, $firstOrder], [$second, $secondOrder]] as [$record, $order]) {
            $row = $rows->get($record->number.'/2026');
            $this->assertNotNull($row);
            $this->assertEquals($record->amount, $row['amount']);
            $this->assertSame($order->number, $row['order']);
            $this->assertSame($order->items->first()->service->name, $row['body']);
            $this->assertStringContainsString('Contrato municipal.', $row['footer']);
            $this->assertStringContainsString('Empenho: '.$record->number.'/2026', $row['footer']);
        }
        Queue::assertNotPushed(ProcessCommitmentBillingBatchJob::class);
        $page->fillForm(['emit_nfse' => true])->callMountedAction()->assertHasNoActionErrors();
        $batch = CommitmentBillingBatch::sole();
        $this->assertTrue($batch->emit_nfse);
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $batch->items()->pluck('customer_commitment_id')->all());
        $this->assertNull($unselected->fresh()->billing);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_confirmed_invoice_opens_emission_specific_modal_and_submits_existing_nfse_without_checkbox(): void
    {
        CommitmentSetting::query()->update(['payment_method' => Method::BANK_TRANSFER->value, 'payment_condition' => Condition::DAYS_30->value, 'nfse_footer_message' => 'Contrato municipal.']);
        $record = $this->commitment();
        $order = $this->order();
        $billing = app(ApproveCommitmentMatchService::class)->approve($this->company->id, $record->id, $order->id, $this->user->id);
        $batch = app(RequestCommitmentBillingService::class)->request($this->company->id, [$record->id], $this->user->id);
        app(ProcessCommitmentBillingBatchService::class)->process($batch->id, $batch->company_id, $batch->run_token);
        $billing->refresh();
        $invoiceId = $billing->invoice_id;
        $documentId = $billing->invoice->fiscalDocuments()->sole()->id;
        $this->assertSame(BillingStatus::CONFIRMED, $billing->status);

        $page = Livewire::test(ListCustomerCommitments::class)->mountAction(TestAction::make('bill_commitment')->table($record))
            ->assertActionMounted(TestAction::make('bill_commitment')->table($record));
        $instance = $page->instance();
        $action = $instance->getMountedAction();
        $this->assertSame('Solicitar emissão da NFS-e', $action->getModalHeading());
        $this->assertSame('Solicitar emissão', $action->getModalSubmitActionLabel());
        $this->assertStringContainsString('A fatura já está confirmada.', $action->getModalDescription());
        $entries = collect($instance->getSchema($instance->getMountedActionSchemaName())->getFlatComponents(withHidden: true));
        $this->assertTrue($entries->first(fn ($entry) => $entry->getName() === 'nfse_service_id')->isHidden());
        $this->assertTrue($entries->first(fn ($entry) => $entry->getName() === 'emit_nfse')->isHidden());
        $preview = $entries->first(fn ($entry) => $entry instanceof RepeatableEntry && $entry->getName() === 'billing_preview');
        $fields = collect($preview->getItems()[0]->getComponents())->map(fn ($entry) => $entry->getName());
        $this->assertContains('invoice', $fields);
        $this->assertContains('fiscal_status', $fields);
        $this->assertNotContains('method', $fields);
        $this->assertNotContains('condition', $fields);
        $this->assertSame($billing->invoice->invoice_number, $preview->getState()[0]['invoice']);

        $page->callMountedAction()->assertHasNoActionErrors()->assertNoRedirect();
        $emissionBatch = CommitmentBillingBatch::latest('id')->firstOrFail();
        $this->assertTrue($emissionBatch->emit_nfse);
        $this->assertSame(2, CommitmentBillingBatch::count());
        $this->mock(NfseDocumentService::class)->shouldReceive('emitir')->once()->andReturnUsing(function ($document) use ($documentId) {
            $this->assertSame($documentId, $document->id);
            $document->update(['nfse_status' => NfeStatus::QUEUED]);

            return true;
        });
        app(ProcessCommitmentBillingBatchService::class)->process($emissionBatch->id, $emissionBatch->company_id, $emissionBatch->run_token);
        $this->assertSame(BillingStatus::EMISSION_QUEUED, $billing->fresh()->status, $billing->fresh()->last_error ?? '');
        $this->assertSame($invoiceId, $billing->fresh()->invoice_id);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('fiscal_documents', 1);
        $this->assertDatabaseCount('account_receivables', 1);
    }

    public function test_navigation_and_list_expose_commitments_and_keep_tenant_scope(): void
    {
        $record = $this->commitment();
        $otherCompany = Company::factory()->create();
        $otherCompany->partners()->attach($this->customer, ['type' => json_encode(['customer'])]);
        $foreign = $record->replicate();
        $foreign->company_id = $otherCompany->id;
        $foreignFile = $record->importFile->replicate();
        $foreignImport = $record->importFile->import->replicate();
        $foreignImport->company_id = $otherCompany->id;
        $foreignImport->save();
        $foreignFile->fill(['company_id' => $otherCompany->id, 'commitment_import_id' => $foreignImport->id])->save();
        $foreign->commitment_import_file_id = $foreignFile->id;
        $foreign->save();
        $this->assertContains(CustomerCommitmentResource::class, Filament::getPanel('admin')->getResources());
        Livewire::test(ListCustomerCommitments::class)->assertSuccessful()->assertCanSeeTableRecords([$record])->assertCanNotSeeTableRecords([$foreign]);
    }

    public function test_review_page_shows_side_by_side_values_and_prioritizes_oldest_order_in_approval(): void
    {
        $record = $this->commitment();
        $new = $this->order(attributes: ['order_date' => '2026-02-01']);
        $old = $this->order(attributes: ['order_date' => '2026-01-01']);
        $page = Livewire::test(ViewCustomerCommitment::class, ['record' => $record->id])
            ->assertSuccessful()->assertSee('Comparação com ordens de serviço')->assertSee($old->number)->assertSee($new->number)
            ->assertSee('Mais antiga elegível')->assertSee('Deslocamento')->assertSee('Requisição');
        $page->callAction('refresh_matches')->assertHasNoActionErrors();
        $this->assertSame($old->id, $record->fresh()->suggested_service_order_id);
        $page->callAction('approve_match', ['service_order_id' => $old->id])->assertHasNoActionErrors();
        $this->assertSame(MatchStatus::APPROVED, $record->fresh()->status);
        $this->assertDatabaseHas('commitment_billings', ['customer_commitment_id' => $record->id, 'service_order_id' => $old->id]);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_table_groups_actions_on_the_left_and_opens_comparison_without_navigation(): void
    {
        $record = $this->commitment();
        $new = $this->order(attributes: ['order_date' => '2026-02-01']);
        $old = $this->order(attributes: ['order_date' => '2026-01-01']);
        $page = Livewire::test(ListCustomerCommitments::class);
        $table = $page->instance()->getTable();
        $this->assertSame(RecordActionsPosition::BeforeCells, $table->getRecordActionsPosition());
        $this->assertSame('start', $table->getRecordActionsAlignment());
        $this->assertCount(1, $table->getRecordActions());
        $group = $table->getRecordActions()[0];
        $this->assertInstanceOf(ActionGroup::class, $group);
        $this->assertSame(Heroicon::Bars3, $group->getIcon());
        $this->assertNull($table->getRecordUrl($record));
        $page->mountAction(TestAction::make('review_match')->table($record))
            ->assertActionMounted(TestAction::make('review_match')->table($record))->assertNoRedirect();
        $instance = $page->instance();
        $entries = collect($instance->getSchema($instance->getMountedActionSchemaName())->getFlatComponents());
        $candidates = $entries->first(fn ($entry) => $entry instanceof RepeatableEntry && $entry->getName() === 'candidates');
        $this->assertNotNull($candidates);
        $this->assertSame([$old->id, $new->id], array_column($candidates->getState(), 'service_order_id'));
        $this->assertTrue($candidates->getState()[0]['oldest']);
    }

    public function test_table_actions_approve_unlink_ignore_and_reopen_without_leaving_filtered_list(): void
    {
        $record = $this->commitment();
        $order = $this->order();
        $page = Livewire::test(ListCustomerCommitments::class)->filterTable('customer_id', $this->customer->id);
        $page->callAction(TestAction::make('refresh_matches')->table($record))->assertHasNoActionErrors()->assertNoRedirect();
        $this->assertSame($order->id, $record->fresh()->suggested_service_order_id);
        $page->callAction(TestAction::make('approve_match')->table($record), data: ['service_order_id' => $order->id])->assertHasNoActionErrors()->assertNoRedirect();
        $this->assertSame(MatchStatus::APPROVED, $record->fresh()->status);
        $page->callAction(TestAction::make('unlink_order')->table($record), data: ['reason' => 'Escolher outra ordem'])->assertHasNoActionErrors();
        $this->assertDatabaseCount('commitment_billings', 0);
        $page->callAction(TestAction::make('ignore')->table($record), data: ['reason' => 'Documento fora do fluxo'])->assertHasNoActionErrors();
        $this->assertSame(MatchStatus::IGNORED, $record->fresh()->status);
        $page->callAction(TestAction::make('reopen')->table($record), data: ['reason' => 'Retomar análise'])->assertHasNoActionErrors()->assertNoRedirect();
        $this->assertSame(MatchStatus::SUGGESTED, $record->fresh()->status);
        $this->assertSame($this->customer->id, (int) data_get($page->instance()->tableFilters, 'customer_id.value'));
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_table_can_correct_extraction_and_enable_approval_for_the_row(): void
    {
        $record = $this->commitment(['patrimony' => null], ['Patrimônio não identificado.']);
        $order = $this->order();
        Livewire::test(ListCustomerCommitments::class)
            ->callAction(TestAction::make('correct_extraction')->table($record), data: $this->correctedData($record, ['patrimony' => '142186', 'acknowledge_issues' => true]))
            ->assertHasNoActionErrors()->assertNoRedirect();
        $this->assertSame($order->id, $record->fresh()->suggested_service_order_id);
        $this->assertSame(MatchStatus::SUGGESTED, $record->fresh()->status);
    }

    public function test_action_rejects_a_different_value_without_reserving_the_order(): void
    {
        $record = $this->commitment();
        $order = $this->order(401);
        Livewire::test(ViewCustomerCommitment::class, ['record' => $record->id])
            ->assertSee('Valor total diferente')
            ->assertActionDisabled('approve_match');
        $this->assertDatabaseCount('commitment_billings', 0);
    }

    public function test_correction_action_confirms_extraction_and_updates_suggestions(): void
    {
        $record = $this->commitment(['patrimony' => null], ['Patrimônio não identificado.']);
        $order = $this->order();
        Livewire::test(ViewCustomerCommitment::class, ['record' => $record->id])
            ->assertSee('Patrimônio não identificado.')
            ->callAction('correct_extraction', $this->correctedData($record, ['patrimony' => '142186', 'acknowledge_issues' => true]))
            ->assertHasNoActionErrors();
        $this->assertSame($order->id, $record->fresh()->suggested_service_order_id);
        $this->assertSame(MatchStatus::SUGGESTED, $record->fresh()->status);
    }

    public function test_unlink_ignore_and_reopen_actions_are_available_in_the_review_page(): void
    {
        $record = $this->commitment();
        $order = $this->order();
        $page = Livewire::test(ViewCustomerCommitment::class, ['record' => $record->id]);
        $page->callAction('approve_match', ['service_order_id' => $order->id])->assertHasNoActionErrors();
        $page->callAction('unlink_order', ['reason' => 'Escolher outra ordem'])->assertHasNoActionErrors();
        $this->assertDatabaseCount('commitment_billings', 0);
        $page->callAction('ignore', ['reason' => 'Documento fora do fluxo'])->assertHasNoActionErrors();
        $this->assertSame(MatchStatus::IGNORED, $record->fresh()->status);
        $page->callAction('reopen', ['reason' => 'Retomar análise'])->assertHasNoActionErrors();
        $this->assertSame(MatchStatus::SUGGESTED, $record->fresh()->status);
    }

    public function test_list_can_refresh_selected_commitments_and_filter_by_import_batch(): void
    {
        $first = $this->commitment();
        $second = $this->commitment();
        $this->order();
        Livewire::test(ListCustomerCommitments::class)
            ->callTableBulkAction('refresh_selected', [$first->id, $second->id])->assertHasNoActionErrors();
        $this->assertSame(MatchStatus::SUGGESTED, $first->fresh()->status);
        $this->assertSame(MatchStatus::SUGGESTED, $second->fresh()->status);
        Livewire::test(ListCustomerCommitments::class)->filterTable('import_id', $first->importFile->commitment_import_id)
            ->assertCanSeeTableRecords([$first])->assertCanNotSeeTableRecords([$second]);
    }

    public function test_new_import_searches_orders_and_persists_suggestions_automatically(): void
    {
        $this->company->update(['document_number' => '12345678000195']);
        $this->customer->update(['document_number' => '83021808000182']);
        $order = $this->order();
        $text = file_get_contents(base_path('tests/Fixtures/Commitments/chapeco-commitment.txt'));
        $this->mock(PdfTextExtractor::class)->shouldReceive('extract')->once()->andReturn(ExtractedPdf::fromText($text));
        app(ImportCommitmentFilesService::class)->import($this->company->id, $this->customer->id, $this->user->id, ImportType::COMMITMENTS, [UploadedFile::fake()->createWithContent('empenho.pdf', "%PDF-1.4\nfixture")]);
        Queue::pushed(ProcessCommitmentImportFileJob::class)->first()->handle(app(ProcessCommitmentImportFileService::class));
        $record = CustomerCommitment::firstOrFail();
        $this->assertSame(MatchStatus::SUGGESTED, $record->status);
        $this->assertSame($order->id, $record->suggested_service_order_id);
    }

    public function test_financial_permission_is_required_for_reconciliation_pages(): void
    {
        $this->user->companyAccesses()->create(['company_id' => $this->company->id, 'permissions' => ['cash_movements.access'], 'panels' => ['admin' => true], 'navigation' => []]);
        Livewire::test(ListCustomerCommitments::class)->assertForbidden();
    }

    public function test_record_from_another_tenant_cannot_be_opened_in_review(): void
    {
        $record = $this->commitment();
        $other = Company::factory()->create();
        $this->user->companies()->attach($other, ['role' => 'member', 'is_active' => true]);
        Filament::setTenant($other);
        $this->expectException(ModelNotFoundException::class);
        Livewire::test(ViewCustomerCommitment::class, ['record' => $record->id]);
    }
}
