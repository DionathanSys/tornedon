<?php

namespace Tests\Feature\Filament\Bpo;

use App\Filament\Bpo\Resources\CashMovementResource;
use App\Filament\Bpo\Resources\CashMovements\Pages\ListMovements;
use App\Filament\Clusters\Financial\Resources\CashMovements\Pages\ListCashMovements;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Models\CashMovement;
use App\Models\ChartAccount;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\User;
use App\Services\Access\CompanyTeamService;
use App\Services\Access\PermissionCatalog;
use App\Services\AccountReceivable\AccountReceivableService;
use App\Services\Financial\CashMovementService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class CashMovementsWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $user;

    private Company $company;

    private FinancialAccount $source;

    private FinancialAccount $destination;

    private FinancialCategory $category;

    private int $engagementId;

    private int $roleId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->user = User::factory()->create();
        $this->company = Company::factory()->create();
        $this->owner->companies()->attach($this->company, ['role' => 'owner', 'is_active' => true]);
        $teams = app(CompanyTeamService::class);
        $role = $teams->saveRole($this->owner, $this->company, ['name' => 'Tesouraria BPO', 'permissions' => PermissionCatalog::templates()['Financeiro'], 'panels' => ['bpo']]);
        $this->roleId = $role->id;
        $this->engagementId = $teams->authorizeBpo($this->owner, $this->company, $this->user, [$role->id], false, [])->id;
        $this->source = FinancialAccount::create(['company_id' => $this->company->id, 'name' => 'Banco principal', 'type' => 'bank', 'is_active' => true]);
        $this->destination = FinancialAccount::create(['company_id' => $this->company->id, 'name' => 'Conta reserva', 'type' => 'bank', 'is_active' => true]);
        $this->category = FinancialCategory::create(['company_id' => $this->company->id, 'name' => 'Transferências', 'is_active' => true, 'allow_cash_movement' => true]);
    }

    private function authenticate(): void
    {
        $this->actingAs($this->user);
        Filament::setCurrentPanel(Filament::getPanel('bpo'));
        Filament::setTenant($this->company);
    }

    private function permissions(array $permissions): void
    {
        app(CompanyTeamService::class)->saveRole($this->owner, $this->company, ['name' => 'Tesouraria BPO', 'permissions' => $permissions, 'panels' => ['bpo']], $this->roleId);
    }

    private function transferData(array $changes = []): array
    {
        return ['source_financial_account_id' => $this->source->id, 'destination_financial_account_id' => $this->destination->id,
            'financial_category_id' => $this->category->id, 'transaction_date' => today()->toDateString(), 'amount' => '150,00', 'description' => 'Reserva de caixa', ...$changes];
    }

    private function transfer(): CashMovement
    {
        $service = app(CashMovementService::class);
        $record = $service->createTransfer(['company_id' => $this->company->id, ...$this->transferData(['amount' => 150])], $this->user->id);
        $this->assertNotNull($record, $service->getMessageUser());

        return $record;
    }

    private function manual(array $changes = []): CashMovement
    {
        return CashMovement::create(['company_id' => $this->company->id, 'financial_account_id' => $this->source->id, 'financial_category_id' => $this->category->id,
            'direction' => 'inflow', 'origin_type' => 'manual', 'transaction_date' => today()->toDateString(), 'amount' => 75, 'description' => 'Entrada avulsa', 'created_by' => $this->user->id, ...$changes]);
    }

    public function test_listing_exposes_admin_columns_filters_and_groups_inside_bpo(): void
    {
        $movement = $this->manual();
        $transfer = $this->transfer();
        $foreign = $this->manual(['company_id' => Company::factory()->create()->id]);
        $this->authenticate();
        $this->get(CashMovementResource::getUrl('index', panel: 'bpo', tenant: $this->company))->assertOk();
        Livewire::test(ListMovements::class)->assertCanSeeTableRecords([$movement, $transfer])->assertCanNotSeeTableRecords([$foreign])
            ->assertTableColumnExists('origin_label')->assertTableColumnExists('tracking_label')->assertTableColumnExists('statement_lines_exists')
            ->filterTable('direction', 'inflow')->assertCanSeeTableRecords([$movement])->assertCanNotSeeTableRecords([$transfer])
            ->resetTableFilters()->searchTable('Transferências')->assertCanSeeTableRecords([$movement, $transfer]);
    }

    public function test_both_panels_render_the_same_category_hierarchy_and_search_it_without_virtual_sql_columns(): void
    {
        $parent = FinancialCategory::create(['company_id' => $this->company->id, 'name' => 'Operacional', 'is_active' => true]);
        $this->category->update(['parent_id' => $parent->id]);
        $movement = $this->manual();
        $this->authenticate();
        Livewire::test(ListMovements::class)->assertSee('Operacional / Transferências')->searchTable('Transferências')->assertCanSeeTableRecords([$movement]);
        $this->actingAs($this->owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ListCashMovements::class)->assertSee('Operacional / Transferências')->searchTable('Transferências')->assertCanSeeTableRecords([$movement]);
    }

    public function test_transfer_modal_creates_both_sides_and_keeps_the_tenant_server_owned(): void
    {
        $this->authenticate();
        Livewire::test(ListMovements::class)->callAction(TestAction::make('create_transfer')->table(), data: $this->transferData(['company_id' => 999999]))->assertHasNoActionErrors();
        $movements = CashMovement::query()->get();
        $this->assertCount(2, $movements);
        $this->assertSame(0.0, (float) $movements->sum('signed_amount'));
        $this->assertSame([$this->company->id], $movements->pluck('company_id')->unique()->all());
    }

    public function test_transfer_modal_rejects_the_same_account_and_a_foreign_account(): void
    {
        $foreign = FinancialAccount::create(['company_id' => Company::factory()->create()->id, 'name' => 'Conta de outra empresa', 'type' => 'bank', 'is_active' => true]);
        $this->authenticate();
        Livewire::test(ListMovements::class)->callAction(TestAction::make('create_transfer')->table(), data: $this->transferData(['destination_financial_account_id' => $this->source->id]))
            ->assertHasActionErrors(['destination_financial_account_id']);
        Livewire::test(ListMovements::class)->callAction(TestAction::make('create_transfer')->table(), data: $this->transferData(['destination_financial_account_id' => $foreign->id]))
            ->assertHasActionErrors(['destination_financial_account_id']);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_editing_from_the_inflow_updates_both_sides_and_reversal_preserves_the_history(): void
    {
        $outflow = $this->transfer();
        $inflow = $outflow->transferCounterpart();
        $this->authenticate();
        Livewire::test(ListMovements::class)->callAction(TestAction::make('edit_transfer')->table($inflow), data: $this->transferData(['amount' => '230,00', 'description' => 'Reserva revisada']))->assertHasNoActionErrors();
        $this->assertSame(230.0, (float) $outflow->fresh()->amount);
        $this->assertSame(230.0, (float) $inflow->fresh()->amount);
        Livewire::test(ListMovements::class)->callAction(TestAction::make('reverse_transfer')->table($outflow))->assertHasNoActionErrors();
        $this->assertDatabaseCount('cash_movements', 4);
        $this->assertNotNull($outflow->fresh()->reversed_at);
        $this->assertSame(0.0, (float) CashMovement::query()->get()->sum('signed_amount'));
        Livewire::test(ListMovements::class)->assertActionHidden(TestAction::make('edit_transfer')->table($outflow))
            ->assertActionHidden(TestAction::make('reverse_transfer')->table($inflow));
    }

    public function test_safe_delete_removes_an_unreconciled_manual_movement(): void
    {
        $movement = $this->manual();
        $this->authenticate();
        Livewire::test(ListMovements::class)->callAction(TestAction::make('delete_safely')->table($movement))->assertHasNoActionErrors();
        $this->assertDatabaseMissing('cash_movements', ['id' => $movement->id]);
    }

    public function test_opening_and_saving_the_edit_modal_without_changes_preserves_the_amount(): void
    {
        $record = $this->transfer();
        $this->authenticate();
        $page = Livewire::test(ListMovements::class)->mountAction(TestAction::make('edit_transfer')->table($record));
        $page->assertActionDataSet(['amount' => '150,00']);
        $page->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame(150.0, (float) $record->fresh()->amount);
        $this->assertSame(150.0, (float) $record->transferCounterpart()->amount);
    }

    public function test_read_only_access_hides_mutations_and_revocation_blocks_mounted_pages(): void
    {
        $record = $this->transfer();
        $this->permissions(['cash_movements.view' => 'company', 'financial_accounts.view' => 'company', 'financial_categories.view' => 'company']);
        $this->authenticate();
        $page = Livewire::test(ListMovements::class)->assertActionHidden(TestAction::make('create_transfer')->table())
            ->assertActionHidden(TestAction::make('edit_transfer')->table($record))->assertActionHidden(TestAction::make('reverse_transfer')->table($record))
            ->assertActionHidden(TestAction::make('delete_safely')->table($record));
        app(CompanyTeamService::class)->endBpo($this->owner, $this->company, $this->engagementId);
        $page->call('$refresh')->assertForbidden();
    }

    public function test_reconciled_transfer_cannot_be_edited_but_can_be_reversed(): void
    {
        $transfer = $this->transfer();
        $import = BankStatementImport::create(['company_id' => $this->company->id, 'financial_account_id' => $this->source->id, 'source' => 'ofx', 'file_name' => 'test.ofx', 'status' => 'completed', 'created_by' => $this->user->id]);
        BankStatementLine::create(['company_id' => $this->company->id, 'bank_statement_import_id' => $import->id, 'financial_account_id' => $this->source->id, 'cash_movement_id' => $transfer->id,
            'transaction_date' => today(), 'amount' => 150, 'description' => 'Transferência conciliada', 'reconciliation_status' => 'reconciled', 'external_id' => 'TRANSFER-1', 'transaction_key' => 'TRANSFER-1']);
        $this->authenticate();
        Livewire::test(ListMovements::class)->assertActionHidden(TestAction::make('edit_transfer')->table($transfer->transferCounterpart()))
            ->assertActionVisible(TestAction::make('reverse_transfer')->table($transfer))
            ->filterTable('conciliado', true)->assertCanSeeTableRecords([$transfer])->assertCanNotSeeTableRecords([$transfer->transferCounterpart()]);
    }

    public function test_safe_delete_of_a_receipt_reopens_the_installment_and_requires_title_reversal_permission(): void
    {
        $service = app(AccountReceivableService::class);
        $title = $service->create(['company_id' => $this->company->id, 'manual_counterparty_name' => 'Cliente do recebimento', 'description' => 'Recebível da operação', 'due_date' => today()->toDateString(), 'due_amount' => 300, 'installment_count' => 1], $this->user->id);
        $payment = $service->registerInstallmentPayment($title->installments()->first(), 100, today()->toDateString(), ['financial_account_id' => $this->source->id, 'user_id' => $this->user->id]);
        $this->assertNotNull($payment, $service->getMessageUser());
        $movement = CashMovement::where('origin_id', $payment->id)->where('origin_type', $payment::class)->sole();
        $this->authenticate();
        $permissions = PermissionCatalog::templates()['Financeiro'];
        unset($permissions['account_receivables.reverse']);
        $this->permissions($permissions);
        Livewire::test(ListMovements::class)->assertActionHidden(TestAction::make('delete_safely')->table($movement));
        $this->permissions(PermissionCatalog::templates()['Financeiro']);
        Livewire::test(ListMovements::class)->callAction(TestAction::make('delete_safely')->table($movement))->assertHasNoActionErrors();
        $this->assertDatabaseMissing('account_receivable_installment_payments', ['id' => $payment->id]);
        $this->assertDatabaseMissing('cash_movements', ['id' => $movement->id]);
        $this->assertSame(300.0, (float) $title->installments()->first()->balance_amount);
    }

    public function test_bulk_classification_updates_selected_records_and_transfer_edit_keeps_the_chart_account(): void
    {
        $chart = ChartAccount::create(['company_id' => $this->company->id, 'code' => '1', 'name' => 'Tesouraria', 'type' => 'other', 'nature' => 'debit', 'is_active' => true, 'is_postable' => true]);
        $movement = $this->manual();
        $unselected = $this->manual();
        $transfer = $this->transfer();
        $this->category->update(['chart_account_id' => $chart->id]);
        $this->authenticate();
        Livewire::test(ListMovements::class)->selectTableRecords([$movement->id])->callAction(TestAction::make('refresh_chart_account_from_category')->table()->bulk())->assertHasNoActionErrors();
        $this->assertSame($chart->id, $movement->fresh()->chart_account_id);
        $this->assertNull($unselected->fresh()->chart_account_id);
        Livewire::test(ListMovements::class)->callAction(TestAction::make('edit_transfer')->table($transfer), data: $this->transferData())->assertHasNoActionErrors();
        $this->assertSame($chart->id, $transfer->fresh()->chart_account_id);
        $this->assertSame($chart->id, $transfer->transferCounterpart()->chart_account_id);
        Livewire::test(ListMovements::class)->callAction(TestAction::make('reverse_transfer')->table($transfer))->assertHasNoActionErrors();
        $this->assertSame($chart->id, $transfer->reversals()->sole()->chart_account_id);
    }

    public function test_own_update_scope_requires_access_to_both_sides_of_the_transfer(): void
    {
        $transfer = $this->transfer();
        $transfer->transferCounterpart()->update(['created_by' => $this->owner->id]);
        $permissions = PermissionCatalog::templates()['Financeiro'];
        $permissions['cash_movements.update'] = 'own';
        $permissions['cash_movements.reverse'] = 'own';
        $this->permissions($permissions);
        $this->authenticate();
        Livewire::test(ListMovements::class)->assertActionHidden(TestAction::make('edit_transfer')->table($transfer))
            ->assertActionHidden(TestAction::make('reverse_transfer')->table($transfer));
    }

    public function test_a_partially_visible_transfer_cannot_be_edited_or_deleted_as_a_standalone_movement(): void
    {
        $transfer = $this->transfer();
        $otherSide = $transfer->transferCounterpart();
        $otherSide->update(['created_by' => $this->owner->id]);
        $permissions = PermissionCatalog::templates()['Financeiro'];
        $permissions['cash_movements.view'] = 'own';
        $this->permissions($permissions);
        $this->authenticate();
        Livewire::test(ListMovements::class)->assertCanSeeTableRecords([$transfer])->assertCanNotSeeTableRecords([$otherSide])
            ->assertActionHidden(TestAction::make('edit')->table($transfer))->assertActionHidden(TestAction::make('delete_safely')->table($transfer));
        $this->get(CashMovementResource::getUrl('edit', ['record' => $transfer], panel: 'bpo', tenant: $this->company))->assertForbidden();
    }

    public function test_listing_query_count_is_bounded_for_fifty_rows_including_transfers(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->transfer();
        }
        $this->authenticate();
        $page = Livewire::test(ListMovements::class);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $page->set('tableRecordsPerPage', 50)->assertCountTableRecords(50);
        $queries = DB::getQueryLog();
        DB::flushQueryLog();
        $page->set('tableRecordsPerPage', 15);
        $smallPageQueries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $counts = collect($queries)->countBy('query')->sortDesc()->take(5)->all();
        $this->assertLessThan(250, count($queries), json_encode(['queries' => count($queries), 'repeated' => $counts], JSON_PRETTY_PRINT));
        $this->assertLessThanOrEqual(20, count($queries) - $smallPageQueries, 'O custo das consultas não deve crescer por linha ou por ação.');
    }
}
