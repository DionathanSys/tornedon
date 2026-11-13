<?php

namespace Tests\Feature\Filament\FiscalDocuments;

use App\Enum\FiscalDocument\IssuePurpose;
use App\Enum\FiscalDocument\NfeStatus;
use App\Enum\FiscalDocument\OperationNature;
use App\Enum\FiscalDocument\Status;
use App\Filament\Clusters\Financial\Resources\FiscalDocuments\Pages\ListFiscalDocuments as IncomingDocuments;
use App\Filament\Clusters\Sales\Resources\FiscalDocuments\Pages\ListFiscalDocuments as OutgoingDocuments;
use App\Models\Company;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentItem;
use App\Models\FiscalDocumentItemOrigin;
use App\Models\FiscalDocumentPayload;
use App\Models\Partner;
use App\Models\User;
use App\Services\Access\CompanyTeamService;
use App\Services\FiscalDocument\FiscalCancellationPolicy;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class AdminFiscalDocumentListingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private Partner $partner;

    private array $queryCounts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->company = Company::factory()->create();
        $this->user->companies()->attach($this->company, ['role' => 'owner', 'is_active' => true]);
        $this->partner = Partner::create(['name' => 'Parceiro fiscal', 'document_type' => 'CNPJ', 'document_number' => '12345678000190']);
        $this->actingAs($this->user);
        Filament::setCurrentPanel('admin');
        Filament::setTenant($this->company);
    }

    public function test_outgoing_listing_keeps_action_fields_without_loading_full_payloads(): void
    {
        $document = $this->document();
        FiscalDocumentPayload::create(['company_id' => $this->company->id, 'fiscal_document_id' => $document->id,
            'nfe_payload' => ['correcoes' => [['sequencial' => 1, 'pdf_base64' => base64_encode('%PDF-test')]]]]);
        $page = Livewire::test(OutgoingDocuments::class)->set('activeTab', 'all');
        $record = $page->instance()->getTableRecords()->first();

        $this->assertSame($this->user->id, $record->created_by);
        $this->assertTrue($record->authorized_at->equalTo($document->authorized_at));
        $this->assertFalse($record->relationLoaded('payload'));
        $this->assertFalse($record->relationLoaded('taxDetail'));
        $this->assertArrayNotHasKey('logs', $record->getAttributes());
        $this->assertTrue(app(FiscalCancellationPolicy::class)->evaluate($record)['allowed']);
        $page->assertTableActionVisible('baixar_carta_correcao_nfe', $document);

        $canceled = $this->document(['nfe_status' => NfeStatus::PENDING, 'canceled' => true]);
        Livewire::test(OutgoingDocuments::class)->assertTableActionHidden('emitir', $canceled);
    }

    public function test_incoming_listing_uses_balance_indicator_and_keeps_tenant_boundaries(): void
    {
        $available = $this->document(['operation_type' => '0']);
        $this->item($available, 2);
        $exhausted = $this->document(['operation_type' => '0']);
        $originItem = $this->item($exhausted, 2);
        $return = $this->document(['issue_purpose' => IssuePurpose::DEVOLUCAO, 'operation_nature' => OperationNature::DEVOLUCAO_COMPRA]);
        $returnItem = $this->item($return, 2);
        FiscalDocumentItemOrigin::create([
            'origin_fiscal_document_id' => $exhausted->id, 'origin_fiscal_document_item_id' => $originItem->id,
            'return_fiscal_document_id' => $return->id, 'return_fiscal_document_item_id' => $returnItem->id,
            'linked_quantity' => 2, 'linked_value' => 20,
        ]);
        $foreign = $this->document(['operation_type' => '0', 'company_id' => Company::factory()->create()->id]);

        $page = Livewire::test(IncomingDocuments::class);
        $this->assertTrue((bool) $page->instance()->getTableRecords()->firstWhere('id', $available->id)->has_available_return_balance, $page->instance()->getTable()->getQuery()->toRawSql());
        $page->assertCanSeeTableRecords([$available, $exhausted])->assertCanNotSeeTableRecords([$return, $foreign])
            ->assertTableActionVisible('generatePurchaseReturn', $available)
            ->assertTableActionHidden('generatePurchaseReturn', $exhausted);
        foreach ($page->instance()->getTableRecords() as $record) {
            $this->assertFalse($record->relationLoaded('items'));
            $this->assertFalse($record->relationLoaded('payload'));
            $this->assertFalse($record->relationLoaded('taxDetail'));
            $this->assertArrayNotHasKey('errors_messages', $record->getAttributes());
            $this->assertSame(20.0, $record->items_total);
        }

        $return->update(['nfe_status' => NfeStatus::REJECTED]);
        Livewire::test(IncomingDocuments::class)->assertTableActionVisible('generatePurchaseReturn', $exhausted);
    }

    public function test_outgoing_listing_preserves_own_permissions_and_reloads_revocations(): void
    {
        $own = $this->document();
        $other = $this->document(['created_by' => User::factory()->create()->id]);
        $teams = app(CompanyTeamService::class);
        $member = User::factory()->create();
        $roleData = ['name' => 'Fiscal admin', 'panels' => ['admin'], 'permissions' => [
            'fiscal_documents.view' => 'company', 'fiscal_documents.update' => 'own', 'fiscal_documents.cancel' => 'own',
        ]];
        $role = $teams->saveRole($this->user, $this->company, $roleData);
        $teams->assign($this->user, $this->company, $member, [$role->id]);
        DB::table('fiscal_documents')->where('id', $own->id)->update(['created_by' => $member->id]);
        $this->actingAs($member);

        $page = Livewire::test(OutgoingDocuments::class)->set('activeTab', 'all')
            ->assertTableActionVisible('cancelar_nfe', $own)->assertTableActionHidden('cancelar_nfe', $other);
        $teams->saveRole($this->user, $this->company, [...$roleData, 'permissions' => [
            ...$roleData['permissions'], 'fiscal_documents.cancel' => 'none',
        ]], $role->id);
        $page->call('$refresh')->assertTableActionHidden('cancelar_nfe', $own);
    }

    public function test_listing_preserves_pending_cancellation_policy_without_queries_per_record(): void
    {
        $document = $this->document();
        $document->fiscalIssues()->create([
            'company_id' => $this->company->id, 'reason' => 'cancellation_uncertain',
            'status' => 'awaiting_confirmation', 'active_slot' => 1,
            'message' => 'Aguardando confirmação fiscal', 'history' => [],
        ]);
        $page = Livewire::test(OutgoingDocuments::class)->set('activeTab', 'all');
        $record = $page->instance()->getTableRecords()->first();
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $result = app(FiscalCancellationPolicy::class)->evaluate($record);
            $this->assertSame('cancellation_confirmation_pending', $result['reason']);
            $this->assertSame([], DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_badges_keep_status_counts_and_tenant_boundaries(): void
    {
        $this->document(['nfe_status' => NfeStatus::PENDING, 'nfse_status' => NfeStatus::PENDING]);
        $this->document(['nfe_status' => null, 'nfse_status' => null]);
        $this->document(['nfe_status' => NfeStatus::REJECTED]);
        $this->document(['company_id' => Company::factory()->create()->id, 'nfe_status' => NfeStatus::PENDING]);
        $this->document(['operation_type' => '0', 'nfe_status' => NfeStatus::PENDING]);
        $tabs = Livewire::test(OutgoingDocuments::class)->instance()->getTabs();
        $this->assertSame('2', $tabs[NfeStatus::PENDING->value]->getBadge());
        $this->assertSame('1', $tabs[NfeStatus::REJECTED->value]->getBadge());
        $this->assertSame('0', $tabs[NfeStatus::QUEUED->value]->getBadge());
    }

    public function test_query_count_does_not_grow_with_rows_in_either_admin_listing(): void
    {
        $this->assertConstantListingQueryCount();
    }

    public function test_query_count_stays_constant_for_role_managed_users(): void
    {
        $teams = app(CompanyTeamService::class);
        $role = $teams->saveRole($this->user, $this->company, ['name' => 'Fiscal', 'panels' => ['admin'], 'permissions' => [
            'fiscal_documents.view' => 'company', 'fiscal_documents.update' => 'company', 'fiscal_documents.cancel' => 'company',
            'fiscal_documents.create' => 'company', 'fiscal_documents.issue' => 'company', 'team.manage' => 'company',
        ]]);
        $member = User::factory()->create();
        $teams->assign($this->user, $this->company, $member, [$role->id]);
        $this->actingAs($member);
        $this->user = $member;
        $this->assertConstantListingQueryCount();
    }

    private function assertConstantListingQueryCount(): void
    {
        foreach ([OutgoingDocuments::class => '1', IncomingDocuments::class => '0'] as $pageClass => $operation) {
            $first = $this->document(['operation_type' => $operation]);
            $this->item($first, 2);
            $small = $this->listingQueryCount($pageClass);
            for ($i = 0; $i < 9; $i++) {
                $this->item($this->document(['operation_type' => $operation]), 2);
            }
            $large = $this->listingQueryCount($pageClass);
            $this->assertLessThanOrEqual($small + 5, $large, "{$pageClass}: 1 row = {$small}, 10 rows = {$large}".json_encode($this->queryCounts));
        }
    }

    private function listingQueryCount(string $pageClass): int
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $page = Livewire::test($pageClass);
            if ($pageClass === OutgoingDocuments::class) {
                $page->set('activeTab', 'all');
            }
            $page->assertSuccessful();
            $this->queryCounts = collect(DB::getQueryLog())->countBy('query')->sortDesc()->take(10)->all();

            return count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    }

    private function document(array $attributes = []): FiscalDocument
    {
        return FiscalDocument::create([
            'company_id' => $this->company->id, 'customer_id' => $this->partner->id,
            'created_by' => $this->user->id, 'document_type' => 'nfe', 'operation_type' => '1',
            'nfe_status' => NfeStatus::AUTHORIZED, 'status' => Status::CONFIRMED, 'confirmed' => true,
            'issued_at' => today(), 'movement_at' => today(), 'authorized_at' => now()->startOfSecond(),
            ...$attributes,
        ]);
    }

    private function item(FiscalDocument $document, float $quantity): FiscalDocumentItem
    {
        return $document->items()->create([
            'item_number' => 1, 'description' => 'Material', 'quantity' => $quantity,
            'unit_of_measure' => 'UN', 'unit_price' => 10, 'total_price' => $quantity * 10,
            'included_in_total' => true, 'created_by' => $this->user->id,
        ]);
    }
}
