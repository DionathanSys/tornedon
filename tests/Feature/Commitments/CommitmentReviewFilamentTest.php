<?php

namespace Tests\Feature\Commitments;

use App\Enum\Commitment\ImportType;
use App\Enum\Commitment\MatchStatus;
use App\Filament\Clusters\Financial\Resources\CustomerCommitments\CustomerCommitmentResource;
use App\Filament\Clusters\Financial\Resources\CustomerCommitments\Pages\ListCustomerCommitments;
use App\Filament\Clusters\Financial\Resources\CustomerCommitments\Pages\ViewCustomerCommitment;
use App\Jobs\ProcessCommitmentImportFileJob;
use App\Models\Company;
use App\Models\CustomerCommitment;
use App\Services\Commitments\DTO\ExtractedPdf;
use App\Services\Commitments\ImportCommitmentFilesService;
use App\Services\Commitments\Pdf\PdfTextExtractor;
use App\Services\Commitments\ProcessCommitmentImportFileService;
use Filament\Facades\Filament;
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
