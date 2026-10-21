<?php

namespace Tests\Feature\Commitments;

use App\Enum\Commitment\ImportType;
use App\Enum\Commitment\ProcessingStatus;
use App\Jobs\ProcessCommitmentImportFileJob;
use App\Models\CommitmentImport;
use App\Models\CommitmentImportFile;
use App\Models\CommitmentSetting;
use App\Models\Company;
use App\Models\Partner;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Commitments\DTO\ExtractedPdf;
use App\Services\Commitments\ImportCommitmentFilesService;
use App\Services\Commitments\Pdf\PdfReadingException;
use App\Services\Commitments\Pdf\PdfTextExtractor;
use App\Services\Commitments\ProcessCommitmentImportFileService;
use App\Services\Commitments\ReprocessCommitmentImportFileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CommitmentImportWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Partner $customer;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');
        $this->company = Company::factory()->create(['name' => 'EMPRESA EXEMPLO LTDA', 'document_number' => '12345678000195']);
        $this->customer = Partner::factory()->create(['document_number' => '83021808000182']);
        $this->company->partners()->attach($this->customer, ['type' => json_encode(['customer']), 'is_active' => true]);
        $this->user = User::factory()->create();
        $this->user->companies()->attach($this->company, ['role' => 'member', 'is_active' => true]);
        $this->actingAs($this->user);
        CommitmentSetting::create(['company_id' => $this->company->id, 'customer_id' => $this->customer->id, 'is_enabled' => true]);
    }

    public function test_upload_stores_private_original_and_enqueues_one_job_per_file(): void
    {
        $import = $this->import([$this->pdf('one.pdf', 'one'), $this->pdf('two.pdf', 'two')]);
        $this->assertCount(2, $import->files);
        Queue::assertPushed(ProcessCommitmentImportFileJob::class, 2);
        $file = $import->files->first();
        $attachment = $file->attachments()->firstOrFail();
        $this->assertSame('local', $attachment->disk);
        $this->assertSame($file->sha256, $attachment->checksum_sha256);
        $this->assertSame($this->user->id, $attachment->uploaded_by);
        $this->assertTrue(Storage::disk('local')->exists($attachment->path));
        $this->assertNotNull($file->processing_token);
        $this->assertDatabaseCount('customer_commitments', 0);
    }

    public function test_renamed_duplicate_is_referenced_in_history_without_new_file_or_job(): void
    {
        $first = $this->import([$this->pdf('original.pdf', 'same')]);
        $second = $this->import([$this->pdf('renamed.pdf', 'same')]);
        $this->assertSame('duplicate', $second->summary[0]['status']);
        $this->assertSame($first->id, $second->summary[0]['original_import_id']);
        $this->assertSame(ProcessingStatus::PROCESSED, $second->status);
        $this->assertDatabaseCount('commitment_import_files', 1);
        $this->assertDatabaseCount('attachments', 1);
        Queue::assertPushed(ProcessCommitmentImportFileJob::class, 1);
    }

    public function test_invalid_file_does_not_prevent_other_files_from_being_imported(): void
    {
        $import = $this->import([$this->pdf(), UploadedFile::fake()->createWithContent('text.pdf', 'not pdf')]);
        $this->assertCount(1, $import->files);
        $this->assertSame('rejected', $import->summary[1]['status']);
        Queue::assertPushed(ProcessCommitmentImportFileJob::class, 1);
    }

    public function test_processing_persists_values_in_cents_and_redelivery_does_not_duplicate_results(): void
    {
        $this->mockExtractor('chapeco-commitment.txt');
        $import = $this->import([$this->pdf()]);
        $job = Queue::pushed(ProcessCommitmentImportFileJob::class)->first();
        $job->handle(app(ProcessCommitmentImportFileService::class));
        $job->handle(app(ProcessCommitmentImportFileService::class));
        $file = $import->files()->firstOrFail();
        $commitment = $file->commitments()->firstOrFail();
        $this->assertSame(ProcessingStatus::PROCESSED, $file->status);
        $this->assertSame(ProcessingStatus::PROCESSED, $import->refresh()->status);
        $this->assertSame(40000, (int) $commitment->getRawOriginal('amount'));
        $this->assertSame('142186', $commitment->patrimony);
        $this->assertSame(1, $file->attempts);
        $this->assertDatabaseCount('customer_commitments', 1);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('account_receivable_installment_payments', 0);
    }

    public function test_sync_queue_processes_after_commit_with_original_and_summary_available(): void
    {
        Queue::fake()->except(ProcessCommitmentImportFileJob::class);
        $this->mockExtractor('chapeco-commitment.txt');
        $import = $this->import([$this->pdf()]);
        $this->assertSame(ProcessingStatus::PROCESSED, $import->status);
        $this->assertSame('accepted', $import->summary[0]['status']);
        $this->assertDatabaseCount('customer_commitments', 1);
    }

    public function test_report_requires_exactly_one_uploaded_file(): void
    {
        $this->expectException(ValidationException::class);
        $this->import([$this->pdf('one.pdf', 'one'), $this->pdf('two.pdf', 'two')], ImportType::PAYMENTS);
    }

    public function test_report_persists_each_payment_with_date_and_page_continuation(): void
    {
        $this->mockExtractor('chapeco-payments.txt');
        $import = $this->import([$this->pdf()], ImportType::PAYMENTS);
        Queue::pushed(ProcessCommitmentImportFileJob::class)->first()->handle(app(ProcessCommitmentImportFileService::class));
        $file = $import->files()->firstOrFail();
        $this->assertSame(ProcessingStatus::PROCESSED, $file->status);
        $this->assertSame(167000, (int) $file->getRawOriginal('declared_total'));
        $this->assertSame(167000, (int) $file->getRawOriginal('extracted_total'));
        $this->assertSame(4, $file->line_count);
        $line = $file->paymentLines()->where('position', 2)->firstOrFail();
        $this->assertSame('2026-08-27', $line->payment_date->toDateString());
        $this->assertSame(1, $line->page_start);
        $this->assertSame(2, $line->page_end);
        $this->assertSame(20000, (int) $line->getRawOriginal('amount'));
        $this->assertDatabaseCount('commitment_receipts', 0);
    }

    public function test_report_creditor_name_does_not_have_to_match_importing_company(): void
    {
        $this->company->update(['name' => 'EMPRESA DE TESTES DIFERENTE DO PDF']);
        $this->mockExtractor('chapeco-payments.txt');
        $import = $this->import([$this->pdf()], ImportType::PAYMENTS);
        Queue::pushed(ProcessCommitmentImportFileJob::class)->first()->handle(app(ProcessCommitmentImportFileService::class));
        $file = $import->files()->firstOrFail();
        $this->assertSame(ProcessingStatus::PROCESSED, $file->status);
        $this->assertNotContains('Nome do credor não corresponde à empresa selecionada.', $file->extracted_data['issues']);
        $this->assertSame(4, $file->paymentLines()->count());
        $this->assertSame($this->company->id, $file->paymentLines()->first()->company_id);
    }

    public function test_different_pdf_with_same_commitment_identity_requires_review_and_preserves_original(): void
    {
        $this->mockExtractor('chapeco-commitment.txt');
        $this->import([$this->pdf('one.pdf', 'one'), $this->pdf('two.pdf', 'two')]);
        foreach (Queue::pushed(ProcessCommitmentImportFileJob::class) as $job) {
            $job->handle(app(ProcessCommitmentImportFileService::class));
        }
        $files = CommitmentImportFile::orderBy('id')->get();
        $this->assertSame(ProcessingStatus::NEEDS_REVIEW, $files[1]->status);
        $this->assertSame($files[0]->commitments()->first()->id, $files[1]->extracted_data['existing_commitment_id']);
        $this->assertDatabaseCount('customer_commitments', 1);
    }

    public function test_reading_failure_can_be_reprocessed_and_an_old_job_cannot_claim_the_new_run(): void
    {
        $this->mock(PdfTextExtractor::class)->shouldReceive('extract')->once()->andThrow(new PdfReadingException('PDF ilegível.'));
        $import = $this->import([$this->pdf()]);
        $oldJob = Queue::pushed(ProcessCommitmentImportFileJob::class)->first();
        $oldJob->handle(app(ProcessCommitmentImportFileService::class));
        $file = $import->files()->firstOrFail();
        $this->assertSame(ProcessingStatus::FAILED, $file->status);
        $this->assertSame('PDF ilegível.', $file->last_error);
        app(ReprocessCommitmentImportFileService::class)->execute($file->id, $this->company->id, $this->customer->id, $this->user->id);
        $newJob = Queue::pushed(ProcessCommitmentImportFileJob::class)->last();
        $this->assertNotSame($oldJob->runToken, $newJob->runToken);
        $oldJob->handle(app(ProcessCommitmentImportFileService::class));
        $oldJob->failed(new \RuntimeException('old timeout'));
        $this->assertSame(ProcessingStatus::QUEUED, $file->refresh()->status);
        $this->mockExtractor('chapeco-commitment.txt');
        $newJob->handle(app(ProcessCommitmentImportFileService::class));
        $this->assertSame(ProcessingStatus::PROCESSED, $file->refresh()->status);
        $this->assertSame(2, $file->attempts);
    }

    public function test_reprocessing_preserves_reviewed_results(): void
    {
        $this->mockExtractor('chapeco-commitment.txt');
        $import = $this->import([$this->pdf()]);
        Queue::pushed(ProcessCommitmentImportFileJob::class)->first()->handle(app(ProcessCommitmentImportFileService::class));
        $file = $import->files()->firstOrFail();
        $file->commitments()->first()->update(['reviewed_at' => now()]);
        $this->expectException(ValidationException::class);
        app(ReprocessCommitmentImportFileService::class)->execute($file->id, $this->company->id, $this->customer->id, $this->user->id);
    }

    public function test_review_made_while_pdf_is_being_read_is_not_overwritten(): void
    {
        $this->mockExtractor('chapeco-commitment.txt');
        $import = $this->import([$this->pdf()]);
        Queue::pushed(ProcessCommitmentImportFileJob::class)->first()->handle(app(ProcessCommitmentImportFileService::class));
        $file = $import->files()->firstOrFail();
        app(ReprocessCommitmentImportFileService::class)->execute($file->id, $this->company->id, $this->customer->id, $this->user->id);
        $text = file_get_contents(base_path('tests/Fixtures/Commitments/chapeco-commitment.txt'));
        $this->mock(PdfTextExtractor::class)->shouldReceive('extract')->once()->andReturnUsing(function () use ($file, $text) {
            $file->commitments()->first()->update(['reviewed_at' => now(), 'review_reason' => 'Revisado durante a leitura']);

            return ExtractedPdf::fromText($text);
        });
        Queue::pushed(ProcessCommitmentImportFileJob::class)->last()->handle(app(ProcessCommitmentImportFileService::class));
        $this->assertSame(ProcessingStatus::FAILED, $file->refresh()->status);
        $this->assertSame('Revisado durante a leitura', $file->commitments()->first()->review_reason);
    }

    public function test_stalled_queue_entry_can_be_recovered_with_a_new_generation(): void
    {
        $import = $this->import([$this->pdf()]);
        $file = $import->files()->firstOrFail();
        $token = $file->processing_token;
        $this->travel(6)->minutes();
        app(ReprocessCommitmentImportFileService::class)->execute($file->id, $this->company->id, $this->customer->id, $this->user->id);
        $this->assertNotSame($token, $file->refresh()->processing_token);
        Queue::assertPushed(ProcessCommitmentImportFileJob::class, 2);
    }

    public function test_worker_checks_access_again_when_membership_has_been_revoked(): void
    {
        $import = $this->import([$this->pdf()]);
        $this->user->companies()->updateExistingPivot($this->company->id, ['is_active' => false]);
        Queue::pushed(ProcessCommitmentImportFileJob::class)->first()->handle(app(ProcessCommitmentImportFileService::class));
        $this->assertSame(ProcessingStatus::FAILED, $import->files()->firstOrFail()->status);
        $this->assertDatabaseCount('customer_commitments', 0);
    }

    public function test_disabled_flow_rejects_upload_before_creating_a_batch(): void
    {
        CommitmentSetting::query()->update(['is_enabled' => false]);
        try {
            $this->import([$this->pdf()]);
            $this->fail('Fluxo desabilitado deve impedir importação.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('customer_id', $exception->errors());
        }
        $this->assertDatabaseCount('commitment_imports', 0);
    }

    public function test_storage_is_compensated_when_database_transaction_fails_after_upload(): void
    {
        $this->mock(AuditRecorder::class)->shouldReceive('recordModelEvent')->andThrow(new \RuntimeException('audit failure'));
        $import = $this->import([$this->pdf()]);
        $this->assertSame('rejected', $import->summary[0]['status']);
        $this->assertDatabaseCount('commitment_import_files', 0);
        $this->assertDatabaseCount('attachments', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
        Queue::assertNothingPushed();
    }

    private function import(array $files, ImportType $type = ImportType::COMMITMENTS): CommitmentImport
    {
        return app(ImportCommitmentFilesService::class)->import($this->company->id, $this->customer->id, $this->user->id, $type, $files);
    }

    private function pdf(string $name = 'empenho.pdf', string $content = 'synthetic'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n".$content);
    }

    private function mockExtractor(string $fixture): void
    {
        $text = str_replace("\n---PAGE---\n", "\f", file_get_contents(base_path('tests/Fixtures/Commitments/'.$fixture)));
        $this->mock(PdfTextExtractor::class)->shouldReceive('extract')->andReturn(ExtractedPdf::fromText($text));
    }
}
