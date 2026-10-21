<?php

namespace Tests\Feature\Commitments;

use App\Enum\Commitment\PaymentLineStatus;
use App\Filament\Clusters\Financial\Resources\CommitmentImports\RelationManagers\FilesRelationManager;
use App\Filament\Clusters\Financial\Resources\CommitmentPaymentImports\Pages\ViewCommitmentPaymentImport;
use App\Filament\Clusters\Financial\Resources\CommitmentPaymentImports\RelationManagers\PaymentLinesRelationManager;
use App\Models\Company;
use App\Services\AccountReceivable\AccountReceivableService;
use App\Services\Commitments\MatchCommitmentPaymentsService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesCommitmentPaymentFixtures;
use Tests\TestCase;

class CommitmentPaymentsFilamentTest extends TestCase
{
    use CreatesCommitmentPaymentFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPaymentContext();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->company);
    }

    private function manager($line)
    {
        return Livewire::test(PaymentLinesRelationManager::class, ['ownerRecord' => $line->importFile->import, 'pageClass' => ViewCommitmentPaymentImport::class]);
    }

    public function test_report_page_exposes_reconciliation_and_row_receipt_uses_report_date(): void
    {
        $line = $this->paymentLine($this->billedCommitment());
        app(MatchCommitmentPaymentsService::class)->refresh($this->company->id, $line->id, $this->user->id);
        Livewire::test(ViewCommitmentPaymentImport::class, ['record' => $line->importFile->commitment_import_id])->assertSuccessful()->assertSee('Conciliação dos pagamentos');
        $this->manager($line)->assertCanSeeTableRecords([$line])
            ->mountAction(TestAction::make('review_payment')->table($line))->assertActionMounted(TestAction::make('review_payment')->table($line));
        $this->manager($line)->callAction(TestAction::make('receive_payment')->table($line), ['financial_account_id' => $this->cashAccount->id, 'competence_date' => '2026-08-01'])->assertHasNoActionErrors();
        $this->assertSame(PaymentLineStatus::RECEIVED, $line->fresh()->status);
        $this->assertDatabaseHas('account_receivable_installment_payments', ['origin' => 'commitment_report', 'financial_account_id' => $this->cashAccount->id, 'payment_date' => '2026-08-27 00:00:00']);
    }

    public function test_bulk_isolates_divergence_and_only_settles_selected_compatible_line(): void
    {
        $first = $this->paymentLine($this->billedCommitment());
        $wrong = $this->paymentLine($this->billedCommitment(), ['amount' => 300], $first->importFile);
        $unselected = $this->paymentLine($this->billedCommitment(), [], $first->importFile);
        app(MatchCommitmentPaymentsService::class)->refreshFile($this->company->id, $first->commitment_import_file_id, $this->user->id);
        $page = $this->manager($first)->selectTableRecords([$first->id, $wrong->id])
            ->mountAction(TestAction::make('receive_selected')->table()->bulk())->assertActionMounted(TestAction::make('receive_selected')->table()->bulk());
        $instance = $page->instance();
        $entries = collect($instance->getSchema($instance->getMountedActionSchemaName())->getFlatComponents());
        $summary = $entries->first(fn ($entry) => $entry instanceof TextEntry && $entry->getName() === 'selection_summary');
        $preview = $entries->first(fn ($entry) => $entry instanceof RepeatableEntry && $entry->getName() === 'selected_payments');
        $this->assertSame('2 linha(s) — R$ 700,00', $summary->getState());
        $this->assertEqualsCanonicalizing([$first->commitment_number.'/2026', $wrong->commitment_number.'/2026'], array_column($preview->getState(), 'reference'));
        $this->assertEquals(700, collect($preview->getState())->sum('amount'));
        $this->assertDatabaseCount('account_receivable_installment_payments', 0);
        $page->fillForm(['financial_account_id' => $this->cashAccount->id])->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame(PaymentLineStatus::RECEIVED, $first->fresh()->status);
        $this->assertSame(PaymentLineStatus::DIVERGENT, $wrong->fresh()->status);
        $this->assertSame(PaymentLineStatus::MATCHED, $unselected->fresh()->status);
        $this->assertDatabaseCount('account_receivable_installment_payments', 1);
    }

    public function test_ignore_reopen_and_correction_preserve_original_values(): void
    {
        $line = $this->paymentLine($this->billedCommitment(), ['amount' => 300]);
        $original = $line->original_data;
        $this->manager($line)->callAction(TestAction::make('correct_payment')->table($line), [
            'commitment_number' => $line->commitment_number, 'fiscal_year' => 2026, 'patrimony' => $line->patrimony,
            'fiscal_document_number' => $line->fiscal_document_number, 'fiscal_document_date' => '2026-08-01',
            'payment_date' => '2026-08-27', 'amount' => 400, 'reason' => 'Conferido com a linha original do PDF.',
        ])->assertHasNoActionErrors();
        $this->assertSame($original, $line->fresh()->original_data);
        $this->assertSame(PaymentLineStatus::MATCHED, $line->fresh()->status);
        $this->manager($line)->callAction(TestAction::make('ignore_payment')->table($line), ['reason' => 'Aguardando conferência'])->assertHasNoActionErrors();
        $this->assertSame(PaymentLineStatus::IGNORED, $line->fresh()->status);
        $this->manager($line)->callAction(TestAction::make('reopen_payment')->table($line), ['reason' => 'Conferência concluída'])->assertHasNoActionErrors();
        $this->assertSame(PaymentLineStatus::MATCHED, $line->fresh()->status);
        $this->assertDatabaseCount('account_receivable_installment_payments', 0);
    }

    public function test_integrity_action_requires_explicit_confirmation_and_reason(): void
    {
        $line = $this->paymentLine($this->billedCommitment());
        $file = $line->importFile;
        $file->update(['status' => 'needs_review', 'extracted_data' => ['issues' => ['Total declarado diferente do extraído.']]]);
        Livewire::test(FilesRelationManager::class, ['ownerRecord' => $file->import, 'pageClass' => ViewCommitmentPaymentImport::class])
            ->callAction(TestAction::make('review_report_integrity')->table($file), ['confirm_integrity' => true, 'reason' => 'Conferi os totais e cada ocorrência no PDF.'])->assertHasNoActionErrors();
        $this->assertSame(PaymentLineStatus::MATCHED, $line->fresh()->status);
        $this->assertSame($this->user->id, data_get($file->fresh()->extracted_data, 'review.user_id'));
    }

    public function test_permissions_and_current_tenant_protect_report_relation_manager(): void
    {
        $line = $this->paymentLine($this->billedCommitment());
        $this->user->companyAccesses()->create(['company_id' => $this->company->id, 'permissions' => ['cash_movements.access'], 'panels' => ['admin' => true], 'navigation' => []]);
        $this->manager($line)->assertForbidden();
        $this->user->companyAccesses()->delete();
        Filament::setTenant(Company::factory()->create());
        $this->manager($line)->assertForbidden();
        $this->assertDatabaseCount('account_receivable_installment_payments', 0);
    }

    public function test_existing_payment_can_be_linked_from_table_without_creating_another_receipt_in_finance(): void
    {
        $billing = $this->billedCommitment();
        $line = $this->paymentLine($billing);
        $payment = app(AccountReceivableService::class)->registerInstallmentPayment($billing->invoice->accountReceivables()->sole()->installments()->sole(), 400, '2026-08-27', ['financial_account_id' => $this->cashAccount->id]);
        app(MatchCommitmentPaymentsService::class)->refresh($this->company->id, $line->id, $this->user->id);
        $this->manager($line)->callAction(TestAction::make('link_existing_receipt')->table($line), ['financial_account_id' => $this->cashAccount->id])->assertHasNoActionErrors();
        $this->assertSame($payment->id, $line->fresh()->receipt->paymentLinks()->sole()->payment_id);
        $this->assertDatabaseCount('account_receivable_installment_payments', 1);
        $this->assertDatabaseCount('cash_movements', 1);
    }
}
