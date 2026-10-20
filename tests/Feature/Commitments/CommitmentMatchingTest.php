<?php

namespace Tests\Feature\Commitments;

use App\Enum\Commitment\MatchStatus;
use App\Enum\ServiceOrder\State;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Partner;
use App\Models\Product;
use App\Models\Requisition;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Commitments\ApproveCommitmentMatchService;
use App\Services\Commitments\CommitmentMatchEngine;
use App\Services\Commitments\ReviewCommitmentService;
use App\Services\Commitments\SuggestCommitmentMatchesService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\CreatesCommitmentReviewFixtures;
use Tests\TestCase;

class CommitmentMatchingTest extends TestCase
{
    use CreatesCommitmentReviewFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createReviewContext();
    }

    public function test_suggests_oldest_opening_not_completion_and_uses_id_as_tie_breaker(): void
    {
        $commitment = $this->commitment();
        $newer = $this->order(attributes: ['order_date' => '2026-01-02', 'completion_date' => '2026-01-03']);
        $older = $this->order(attributes: ['order_date' => '2026-01-01', 'completion_date' => '2026-06-01']);
        $tie = $this->order(attributes: ['order_date' => '2026-01-01']);
        $result = app(SuggestCommitmentMatchesService::class)->refresh($this->company->id, $commitment->id, $this->user->id);
        $this->assertSame($older->id, $result->suggested_service_order_id);
        $this->assertSame(3, $result->suggestion_count);
        $this->assertSame(MatchStatus::SUGGESTED, $result->status);
        $this->assertSame([$older->id, $tie->id, $newer->id], $result->match_diagnostics['eligible_ids']);
        $this->assertDatabaseCount('commitment_billings', 0);
    }

    public function test_matches_exact_cents_and_complete_patrimony_without_partial_or_serial_number_fallback(): void
    {
        $commitment = $this->commitment(['amount' => '400.10', 'net_amount' => '400.10']);
        $correct = $this->order('400.10');
        $different = $this->order('400.11');
        $this->order('400.10', equipmentName: 'CAMINHÃO PAT 14218');
        $ambiguous = $this->order('400.10', equipmentName: 'CAMINHÃO PAT 142186 E PAT 111');
        $noPat = $this->order('400.10', equipmentName: 'CAMINHÃO SEM PAT');
        $noPat->equipment->update(['serial_number' => '142186']);
        $result = app(CommitmentMatchEngine::class)->analyze($commitment);
        $this->assertSame([$correct->id], $result['eligible_ids']);
        $this->assertCount(2, $result['candidates']);
        $this->assertSame(1, collect($result['candidates'])->firstWhere('service_order_id', $different->id)['difference_cents']);
        $this->assertNotContains($ambiguous->id, array_column($result['candidates'], 'service_order_id'));
    }

    public function test_requisition_is_visible_for_diagnosis_but_blocks_nfse_only_flow(): void
    {
        $record = $this->commitment();
        $order = $this->order(350, ['travel_value' => '20.00']);
        $order->items()->first()->update(['discount_amount' => 20]);
        $request = Requisition::create(['company_id' => $this->company->id, 'customer_id' => $this->customer->id, 'number' => 'R-1', 'service_order_id' => $order->id, 'sale_date' => '2026-01-01', 'status' => 'closed', 'stock_consumed' => false]);
        $product = Product::factory()->create(['company_id' => $this->company->id]);
        $request->items()->create(['product_id' => $product->id, 'unit_of_measure' => 'UN', 'quantity' => 1, 'unit_price' => 50, 'discount_amount' => 0]);
        $result = app(CommitmentMatchEngine::class)->analyze($record);
        $this->assertSame([], $result['eligible_ids']);
        $this->assertStringContainsString('somente serviços/NFS-e', $result['candidates'][0]['reason']);
        $this->assertSame(33000, $result['candidates'][0]['services_amount_cents']);
        $this->assertSame(2000, $result['candidates'][0]['travel_amount_cents']);
        $this->assertSame(5000, $result['candidates'][0]['requisition_amount_cents']);
        $this->assertSame(40000, $result['candidates'][0]['total_amount_cents']);
        $request->update(['status' => 'cancelled']);
        $this->assertSame([], app(CommitmentMatchEngine::class)->analyze($record)['eligible_ids']);
    }

    public function test_excludes_open_cancelled_invoiced_foreign_customer_and_foreign_company_orders(): void
    {
        $commitment = $this->commitment();
        $this->order(attributes: ['status' => State::OPEN]);
        $this->order(attributes: ['status' => State::CANCELLED]);
        $invoice = $this->invoice();
        $this->order(attributes: ['invoice_id' => $invoice->id]);
        $this->order(attributes: ['customer_id' => Partner::factory()->create()->id]);
        $this->order(attributes: ['company_id' => Company::factory()->create()->id]);
        $this->assertSame([], app(CommitmentMatchEngine::class)->analyze($commitment)['eligible_ids']);
    }

    public function test_approval_reserves_one_order_and_another_commitment_moves_to_next_oldest(): void
    {
        $first = $this->commitment();
        $second = $this->commitment();
        $old = $this->order();
        $next = $this->order(attributes: ['order_date' => '2026-02-01']);
        $service = app(ApproveCommitmentMatchService::class);
        $billing = $service->approve($this->company->id, $first->id, $old->id, $this->user->id);
        $same = $service->approve($this->company->id, $first->id, $old->id, $this->user->id);
        $this->assertSame($billing->id, $same->id);
        $this->assertSame(40000, $billing->approval_snapshot['selected_order']['total_amount_cents']);
        $this->assertSame(MatchStatus::APPROVED, $first->fresh()->status);
        $this->assertSame([$next->id], app(CommitmentMatchEngine::class)->analyze($second)['eligible_ids']);
        $this->assertDatabaseCount('commitment_billings', 1);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertSame(State::CLOSED, $old->fresh()->status);
        $this->expectException(ValidationException::class);
        $service->approve($this->company->id, $second->id, $old->id, $this->user->id);
    }

    public function test_user_can_choose_a_newer_eligible_order_and_snapshot_records_the_oldest(): void
    {
        $record = $this->commitment();
        $old = $this->order();
        $new = $this->order(attributes: ['order_date' => '2026-02-01']);
        $billing = app(ApproveCommitmentMatchService::class)->approve($this->company->id, $record->id, $new->id, $this->user->id);
        $this->assertSame($new->id, $billing->service_order_id);
        $this->assertSame($old->id, $billing->approval_snapshot['oldest_eligible_order_id']);
    }

    public function test_approval_rechecks_values_instead_of_trusting_saved_suggestion(): void
    {
        $record = $this->commitment();
        $order = $this->order();
        app(SuggestCommitmentMatchesService::class)->refresh($this->company->id, $record->id, $this->user->id);
        $order->items()->first()->update(['unit_price' => 401]);
        $this->expectException(ValidationException::class);
        app(ApproveCommitmentMatchService::class)->approve($this->company->id, $record->id, $order->id, $this->user->id);
    }

    public function test_review_preserves_original_data_and_requires_confirmation_of_extraction_issues(): void
    {
        $record = $this->commitment(['patrimony' => null], ['Patrimônio não identificado.']);
        $this->order();
        $review = app(ReviewCommitmentService::class);
        $original = $record->original_data;
        $this->assertSame([], app(CommitmentMatchEngine::class)->analyze($record)['eligible_ids']);
        $updated = $review->correct($this->company->id, $record->id, $this->user->id, $this->correctedData($record, ['patrimony' => '000142186']));
        $this->assertSame(MatchStatus::UNMATCHED, $updated->status);
        $confirmed = $review->correct($this->company->id, $record->id, $this->user->id, $this->correctedData($updated, ['acknowledge_issues' => true]));
        $this->assertSame(MatchStatus::SUGGESTED, $confirmed->status);
        $this->assertSame('142186', $confirmed->patrimony);
        $this->assertSame($original, $confirmed->original_data);
        $this->assertSame(['Patrimônio não identificado.'], $confirmed->corrected_data['acknowledged_issues']);
        $this->assertDatabaseHas('audit_entries', ['event' => 'commitment.extraction_reviewed', 'auditable_id' => $record->id]);
        $this->assertFalse($record->fresh()->importFile->canReprocess());
    }

    public function test_correction_cannot_create_duplicate_identity_or_inconsistent_values(): void
    {
        $first = $this->commitment();
        $second = $this->commitment();
        foreach ([['number' => '000'.$first->number], ['net_amount' => 399], ['reason' => '']] as $override) {
            try {
                app(ReviewCommitmentService::class)->correct($this->company->id, $second->id, $this->user->id, $this->correctedData($second, $override));
                $this->fail('Correção inválida deve ser rejeitada.');
            } catch (ValidationException) {
                $this->assertSame($second->number, $second->fresh()->number);
                $this->assertNull($second->fresh()->corrected_data);
            }
        }
    }

    public function test_approved_records_cannot_be_corrected_and_unlink_is_audited_before_billing(): void
    {
        $record = $this->commitment();
        $order = $this->order();
        $service = app(ApproveCommitmentMatchService::class);
        $service->approve($this->company->id, $record->id, $order->id, $this->user->id);
        try {
            app(ReviewCommitmentService::class)->correct($this->company->id, $record->id, $this->user->id, $this->correctedData($record));
            $this->fail('Empenho aprovado não pode ser corrigido.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('commitment_billings', 1);
        }
        $result = $service->unlink($this->company->id, $record->id, $this->user->id, 'Escolher outra ordem');
        $this->assertSame(MatchStatus::SUGGESTED, $result->status);
        $this->assertSame('Escolher outra ordem', $result->review_reason);
        $this->assertDatabaseCount('commitment_billings', 0);
        $this->assertDatabaseHas('audit_entries', ['event' => 'commitment.match_unlinked', 'auditable_id' => $record->id]);
    }

    public function test_unlink_rejects_order_that_was_invoiced_by_another_flow(): void
    {
        $record = $this->commitment();
        $order = $this->order();
        app(ApproveCommitmentMatchService::class)->approve($this->company->id, $record->id, $order->id, $this->user->id);
        $order->update(['invoice_id' => $this->invoice()->id, 'status' => State::INVOICED]);
        $this->expectException(ValidationException::class);
        app(ApproveCommitmentMatchService::class)->unlink($this->company->id, $record->id, $this->user->id, 'Tentativa após faturamento');
    }

    public function test_ignore_and_reopen_are_explicit_and_do_not_reserve_an_order(): void
    {
        $record = $this->commitment();
        $this->order();
        $service = app(ReviewCommitmentService::class);
        $ignored = $service->ignore($this->company->id, $record->id, $this->user->id, 'Documento não será utilizado');
        $this->assertSame(MatchStatus::IGNORED, $ignored->status);
        $this->assertSame([], app(CommitmentMatchEngine::class)->analyze($ignored)['eligible_ids']);
        $reopened = $service->reopen($this->company->id, $record->id, $this->user->id, 'Retomar conciliação');
        $this->assertSame(MatchStatus::SUGGESTED, $reopened->status);
        $this->assertDatabaseCount('commitment_billings', 0);
    }

    public function test_approval_rolls_back_reservation_if_audit_fails(): void
    {
        $record = $this->commitment();
        $order = $this->order();
        $this->mock(AuditRecorder::class)->shouldReceive('recordModelEvent')->andThrow(new \RuntimeException('audit failure'));
        try {
            app(ApproveCommitmentMatchService::class)->approve($this->company->id, $record->id, $order->id, $this->user->id);
            $this->fail('A falha da auditoria deve interromper a aprovação.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('audit failure', $exception->getMessage());
        }
        $this->assertDatabaseCount('commitment_billings', 0);
        $this->assertSame(MatchStatus::UNMATCHED, $record->fresh()->status);
    }

    public function test_service_rejects_user_without_active_company_access(): void
    {
        $record = $this->commitment();
        $this->expectException(HttpException::class);
        app(SuggestCommitmentMatchesService::class)->refresh($this->company->id, $record->id, User::factory()->create()->id);
    }

    public function test_cross_company_commitment_id_is_not_resolved(): void
    {
        $record = $this->commitment();
        $this->expectException(ModelNotFoundException::class);
        app(SuggestCommitmentMatchesService::class)->refresh(Company::factory()->create()->id, $record->id, $this->user->id);
    }

    public function test_suggestion_migration_can_be_reapplied_preserving_imported_commitments(): void
    {
        $record = $this->commitment();
        $migration = require database_path('migrations/2026_10_19_000003_add_commitment_match_suggestions.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('customer_commitments', 'suggested_service_order_id'));
        $migration->up();
        $this->assertTrue(Schema::hasColumn('customer_commitments', 'suggested_service_order_id'));
        $this->assertSame(40000, (int) $record->fresh()->getRawOriginal('amount'));
    }

    private function invoice(): Invoice
    {
        return Invoice::create(['company_id' => $this->company->id, 'customer_id' => $this->customer->id, 'invoice_number' => 'I-'.Invoice::count(), 'invoice_date' => '2026-06-01', 'status' => 'pending']);
    }
}
