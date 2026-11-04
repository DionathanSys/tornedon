<?php

namespace Tests\Feature\Filament\Bpo;

use App\Filament\Bpo\Resources\AccountPayableInstallmentResource;
use App\Filament\Bpo\Resources\AccountPayableInstallments\Pages\ListInstallments;
use App\Filament\Bpo\Resources\AccountPayableResource;
use App\Filament\Bpo\Resources\AccountPayables\Pages\ListAccounts as ListPayables;
use App\Filament\Bpo\Resources\AccountPayables\Pages\ViewAccount as ViewPayable;
use App\Filament\Bpo\Resources\AccountPayables\RelationManagers\InstallmentsRelationManager as PayableInstallments;
use App\Filament\Bpo\Resources\AccountPayables\RelationManagers\PaymentsRelationManager as PayablePayments;
use App\Filament\Bpo\Resources\AccountReceivableInstallmentResource;
use App\Filament\Bpo\Resources\AccountReceivableInstallments\Pages\ListInstallments as ListReceivableInstallments;
use App\Filament\Bpo\Resources\AccountReceivableResource;
use App\Filament\Bpo\Resources\AccountReceivables\Pages\ListAccounts as ListReceivables;
use App\Filament\Bpo\Resources\AccountReceivables\Pages\ViewAccount as ViewReceivable;
use App\Filament\Bpo\Resources\AccountReceivables\RelationManagers\InstallmentsRelationManager as ReceivableInstallments;
use App\Filament\Bpo\Resources\AccountReceivables\RelationManagers\PaymentsRelationManager as ReceivablePayments;
use App\Filament\Clusters\Financial\Resources\AccountPayables\RelationManagers\PaymentsRelationManager;
use App\Livewire\Bpo\RecordAttachments;
use App\Models\AccountPayable;
use App\Models\CashMovement;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\User;
use App\Services\Access\CompanyTeamService;
use App\Services\Access\PermissionCatalog;
use App\Services\AccountPayable\AccountPayableService;
use App\Services\AccountReceivable\AccountReceivableService;
use App\Services\Attachments\AttachmentService;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FinancialTitleWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $user;

    private Company $company;

    private CompanyTeamService $teams;

    private int $engagementId;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['attachments.default_disk' => 'local']);
        $this->owner = User::factory()->create();
        $this->user = User::factory()->create();
        $this->company = Company::factory()->create();
        $this->owner->companies()->attach($this->company, ['role' => 'owner', 'is_active' => true]);
        $this->teams = app(CompanyTeamService::class);
        $role = $this->teams->saveRole($this->owner, $this->company, [
            'name' => 'Financeiro', 'permissions' => PermissionCatalog::templates()['Financeiro'], 'panels' => ['bpo'],
        ]);
        $this->engagementId = $this->teams->authorizeBpo($this->owner, $this->company, $this->user, [$role->id], false, [])->id;
    }

    private function authenticate(): void
    {
        $this->actingAs($this->user);
        Filament::setCurrentPanel(Filament::getPanel('bpo'));
        Filament::setTenant($this->company);
    }

    private function title(bool $payable = true)
    {
        $service = app($payable ? AccountPayableService::class : AccountReceivableService::class);
        $record = $service->create(['company_id' => $this->company->id, 'manual_counterparty_name' => $payable ? 'Fornecedor do espaço financeiro' : 'Cliente do espaço financeiro',
            'description' => 'Documento financeiro do layout customizado', 'document_number' => 'DOC-2026',
            'due_date' => today()->toDateString(), 'due_amount' => 200, 'installment_count' => 2], $this->user->id);
        $this->assertNotNull($record, $service->getMessage());

        return $record;
    }

    private function widget($record, array $options = [])
    {
        return Livewire::test(RecordAttachments::class, ['module' => $record instanceof AccountPayable ? 'account_payables' : 'account_receivables', 'recordId' => $record->id, ...$options]);
    }

    public function test_titles_can_be_ignored_restored_and_deleted_through_the_bpo_list(): void
    {
        $payable = $this->title();
        $receivable = $this->title(false);
        $this->authenticate();
        foreach ([[$payable, ListPayables::class], [$receivable, ListReceivables::class]] as [$record, $page]) {
            Livewire::test($page)->callAction(TestAction::make('ignore_financial')->table($record), data: ['reason' => 'Título desconsiderado para revisão'])->assertHasNoActionErrors();
            $this->assertSame(2, $record->installments()->whereNotNull('ignored_at')->count());
            Livewire::test($page)->set('activeTab', 'ignored')->callAction(TestAction::make('restore_consideration')->table($record), data: ['reason' => 'Revisão concluída para considerar novamente'])->assertHasNoActionErrors();
            $this->assertSame(0, $record->installments()->whereNotNull('ignored_at')->count());
            Livewire::test($page)->callAction(TestAction::make('delete')->table($record))->assertHasNoActionErrors();
            $this->assertDatabaseMissing($record->getTable(), ['id' => $record->id]);
        }
    }

    public function test_bulk_delete_checks_every_record_and_rolls_back_an_unauthorized_selection(): void
    {
        $own = $this->title();
        $other = $this->title();
        $other->update(['created_by' => $this->owner->id]);
        $role = $this->teams->saveRole($this->owner, $this->company, ['name' => 'Exclusão própria', 'panels' => ['bpo'], 'permissions' => ['account_payables.view' => 'company', 'account_payables.delete' => 'own']]);
        $this->teams->assign($this->owner, $this->company, $this->user, [$role->id], $this->engagementId);
        $this->authenticate();
        Livewire::test(ListPayables::class)->selectTableRecords([$own->id, $other->id])->callAction(TestAction::make('delete')->table()->bulk())->assertForbidden();
        $this->assertDatabaseHas($own->getTable(), ['id' => $own->id]);
        $this->assertDatabaseHas($other->getTable(), ['id' => $other->id]);
    }

    public function test_title_listing_keeps_admin_filters_columns_and_bounded_authorization_queries(): void
    {
        for ($i = 0; $i < 15; $i++) {
            $this->title();
        }
        $this->authenticate();
        DB::enableQueryLog();
        DB::flushQueryLog();
        Livewire::test(ListPayables::class)->assertTableColumnExists('paid_amount')->assertTableColumnExists('bank_slip_number')->assertCountTableRecords(15);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertLessThan(500, count($queries), 'Consultas na listagem: '.count($queries));
    }

    #[DataProvider('paymentTypes')]
    public function test_workspace_lists_individual_installments_as_accounts(bool $payable): void
    {
        $title = $this->title($payable);
        [$first, $second] = $title->installments()->orderBy('sequence_number')->get()->all();
        $this->authenticate();

        $page = Livewire::test($payable ? ListInstallments::class : ListReceivableInstallments::class)
            ->assertCountTableRecords(2)->assertCanSeeTableRecords([$first, $second])
            ->assertTableColumnExists('due_date')->assertTableColumnExists('balance_amount')
            ->assertSee($payable ? 'Contas a Pagar' : 'Contas a Receber')
            ->assertSee('Editar conta')->assertSee('Excluir conta')
            ->callAction(TestAction::make('ignore_financial')->table($first), data: ['reason' => 'Revisar somente a primeira parcela'])
            ->assertHasNoActionErrors();

        $this->assertNotNull($first->fresh()->ignored_at);
        $this->assertNull($second->fresh()->ignored_at);
        $page->assertCanSeeTableRecords([$second])->assertCanNotSeeTableRecords([$first])
            ->set('activeTab', 'ignored')->assertCanSeeTableRecords([$first])->assertCanNotSeeTableRecords([$second])
            ->callAction(TestAction::make('restore_consideration')->table($first), data: ['reason' => 'Parcela revisada e considerada novamente'])
            ->assertHasNoActionErrors();

        $this->assertNull($first->fresh()->ignored_at);
        $group = $page->instance()->getTable()->getRecordActions()[0];
        $this->assertSame(Heroicon::Bars3, $group->getIcon());
        $this->assertSame('Ações da conta', $group->getTooltip());
        $this->assertArrayHasKey('id', $page->instance()->getTable()->getColumns());
        $this->assertArrayNotHasKey('sequence_number', $page->instance()->getTable()->getColumns());
        foreach ($group->getFlatActions() as $action) {
            $this->assertSame($action->getLabel(), $action->getTooltip());
        }

        $this->get(($payable ? AccountPayableInstallmentResource::class : AccountReceivableInstallmentResource::class)::getUrl(panel: 'bpo', tenant: $this->company))
            ->assertOk()->assertSee($payable ? 'Contas a Pagar' : 'Contas a Receber')->assertDontSee('Nenhuma parcela encontrada');
    }

    #[DataProvider('paymentTypes')]
    public function test_installment_listing_queries_do_not_grow_per_row_or_action(bool $payable): void
    {
        $parent = FinancialCategory::create(['company_id' => $this->company->id, 'name' => 'Despesas', 'is_active' => true]);
        for ($i = 0; $i < 25; $i++) {
            $category = FinancialCategory::create(['company_id' => $this->company->id, 'parent_id' => $parent->id, 'name' => 'Categoria '.$i, 'is_active' => true]);
            $this->title($payable)->installments()->update(['financial_category_id' => $category->id]);
        }
        $this->authenticate();
        $page = Livewire::test($payable ? ListInstallments::class : ListReceivableInstallments::class);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $page->set('tableRecordsPerPage', 50)->assertCountTableRecords(50);
        $queries = DB::getQueryLog();
        DB::flushQueryLog();
        $page->set('tableRecordsPerPage', 15);
        $smallPageQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(250, count($queries), json_encode([
            'total' => count($queries),
            'small_page' => $smallPageQueries,
            'repeated' => collect($queries)->countBy('query')->sortDesc()->take(8)->all(),
        ], JSON_PRETTY_PRINT));
        $this->assertLessThanOrEqual(20, count($queries) - $smallPageQueries, 'As consultas não devem crescer por parcela ou ação.');
    }

    #[DataProvider('paymentTypes')]
    public function test_installment_tab_counts_follow_due_dates_search_and_status_filters(bool $payable): void
    {
        $title = $this->title($payable);
        [$first, $second] = $title->installments()->orderBy('sequence_number')->get()->all();
        $first->update(['due_date' => today()->subDay()]);
        $second->update(['due_date' => today()]);
        $this->authenticate();

        $page = Livewire::test($payable ? ListInstallments::class : ListReceivableInstallments::class);
        $tabs = $page->instance()->getTabs();
        $this->assertSame('2', $tabs['pending']->getBadge());
        $this->assertSame('1', $tabs['overdue']->getBadge());
        $this->assertSame('1', $tabs['today']->getBadge());
        $this->assertSame('2', $tabs['all']->getBadge());
        $page->set('activeTab', 'today')->assertCanSeeTableRecords([$second])->assertCanNotSeeTableRecords([$first]);
        $page->searchTable('sem correspondencia')->assertCountTableRecords(0);
        foreach ($page->instance()->getTabs() as $tab) {
            $this->assertSame('0', $tab->getBadge());
        }
        $page->searchTable('')->filterTable('status', ['cancelled'])->assertCountTableRecords(0);
        $this->assertSame('0', $page->instance()->getTabs()['all']->getBadge());
    }

    #[DataProvider('paymentTypes')]
    public function test_installment_listing_registers_payment_only_for_the_selected_installment(bool $payable): void
    {
        $title = $this->title($payable);
        [$first, $second] = $title->installments()->orderBy('sequence_number')->get()->all();
        $account = FinancialAccount::create(['company_id' => $this->company->id, 'name' => 'Caixa', 'type' => 'cash', 'is_active' => true, 'created_by' => $this->user->id]);
        $this->authenticate();

        Livewire::test($payable ? ListInstallments::class : ListReceivableInstallments::class)
            ->callAction(TestAction::make('register_payment')->table($first), data: [
                'payment_date' => today()->toDateString(), 'amount' => '40,00', 'interest_amount' => '0,00',
                'fine_amount' => '0,00', 'discount_amount' => '0,00', 'financial_account_id' => $account->id,
            ])->assertHasNoActionErrors();

        $amountColumn = $payable ? 'paid_amount' : 'received_amount';
        $this->assertSame(40.0, (float) $first->fresh()->{$amountColumn});
        $this->assertSame(0.0, (float) $second->fresh()->{$amountColumn});
        $this->assertDatabaseCount('cash_movements', 1);
    }

    #[DataProvider('paymentTypes')]
    public function test_editing_and_deleting_a_listed_account_only_changes_its_installment(bool $payable): void
    {
        $title = $this->title($payable);
        [$first, $second] = $title->installments()->orderBy('sequence_number')->get()->all();
        $this->authenticate();

        $page = Livewire::test($payable ? ListInstallments::class : ListReceivableInstallments::class)
            ->mountAction(TestAction::make('edit_installment')->table($first));
        $modal = $page->getMountedActionModalHtml();
        $this->assertStringContainsString('Editar conta #'.$first->id, $modal);
        $this->assertStringContainsString('Valor da conta', $modal);
        $this->assertStringNotContainsString('Valor da parcela', $modal);
        $page->setActionData(['original_amount' => '125,50', 'description' => 'Conta revisada'])->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame(125.5, (float) $first->fresh()->original_amount);
        $this->assertSame(100.0, (float) $second->fresh()->original_amount);
        $page->callAction(TestAction::make('delete_installment')->table($first))->assertHasNoActionErrors();
        $this->assertDatabaseMissing($first->getTable(), ['id' => $first->id]);
        $this->assertDatabaseHas($second->getTable(), ['id' => $second->id]);
        $this->assertDatabaseHas($title->getTable(), ['id' => $title->id]);
    }

    #[DataProvider('paymentTypes')]
    public function test_installment_listing_preserves_parent_ownership_and_rechecks_revoked_access(bool $payable): void
    {
        $own = $this->title($payable);
        $other = $this->title($payable);
        $other->update(['created_by' => $this->owner->id]);
        $module = $payable ? 'account_payables' : 'account_receivables';
        $role = $this->teams->saveRole($this->owner, $this->company, ['name' => 'Contas próprias', 'panels' => ['bpo'], 'permissions' => [$module.'.view' => 'own']]);
        $this->teams->assign($this->owner, $this->company, $this->user, [$role->id], $this->engagementId);
        $this->authenticate();

        $page = Livewire::test($payable ? ListInstallments::class : ListReceivableInstallments::class)->assertCanSeeTableRecords($own->installments)
            ->assertCanNotSeeTableRecords($other->installments)
            ->assertActionHidden(TestAction::make('register_payment')->table($own->installments->first()))
            ->assertActionHidden(TestAction::make('edit_installment')->table($own->installments->first()));
        $this->teams->endBpo($this->owner, $this->company, $this->engagementId);
        $page->call('$refresh')->assertForbidden();
    }

    public function test_both_account_types_render_the_custom_header_attachments_and_two_managers_without_tabs(): void
    {
        $payable = $this->title();
        $receivable = $this->title(false);
        $this->authenticate();
        foreach ([[$payable, AccountPayableResource::class, PayableInstallments::class, PayablePayments::class], [$receivable, AccountReceivableResource::class, ReceivableInstallments::class, ReceivablePayments::class]] as [$record, $resource, $installments, $payments]) {
            $response = $this->get($resource::getUrl('view', ['record' => $record], panel: 'bpo', tenant: $this->company))
                ->assertOk()->assertSee('bpo-account-top-grid', false)->assertSee('bpo-account-relationship-grid', false)
                ->assertSee('Anexos do lançamento')->assertSee('Saldo considerado')->assertSee('DOC-2026')
                ->assertSeeLivewire(RecordAttachments::class)->assertSeeLivewire($installments)->assertSeeLivewire($payments);
            $response->assertDontSee('relationManagerTabs');
            $this->get($resource::getUrl('edit', ['record' => $record], panel: 'bpo', tenant: $this->company))
                ->assertOk()->assertSee('bpo-account-top-grid', false)->assertSee('bpo-account-relationship-grid', false)
                ->assertSee('bpo-form-content', false)->assertSee('Anexos do lançamento')
                ->assertSeeLivewire(RecordAttachments::class)->assertSeeLivewire($installments)->assertSeeLivewire($payments)
                ->assertDontSee('relationManagerTabs')->assertDontSee('Confira antes de salvar');
        }
    }

    public function test_payments_and_receipts_keep_the_existing_financial_workflow_and_refresh_the_summary(): void
    {
        $records = [$this->title(), $this->title(false)];
        $account = FinancialAccount::create(['company_id' => $this->company->id, 'name' => 'Caixa do lançamento', 'type' => 'cash', 'is_active' => true, 'created_by' => $this->user->id]);
        $this->authenticate();
        foreach ($records as $record) {
            $payable = $record instanceof AccountPayable;
            $view = $payable ? ViewPayable::class : ViewReceivable::class;
            $installments = $payable ? PayableInstallments::class : ReceivableInstallments::class;
            $payments = $payable ? PayablePayments::class : ReceivablePayments::class;
            $page = Livewire::test($view, ['record' => $record->id]);
            $this->assertSame(200.0, $page->instance()->accountDetails()['balance']);
            Livewire::test($installments, ['ownerRecord' => $record, 'pageClass' => $view])
                ->callAction(TestAction::make('register_payment')->table($record->installments()->first()), data: [
                    'payment_date' => today()->toDateString(), 'amount' => '40,00', 'interest_amount' => '0,00', 'fine_amount' => '0,00', 'discount_amount' => '0,00',
                    'financial_account_id' => $account->id, 'description' => 'Baixa pela tela customizada',
                ])->assertHasNoActionErrors()->assertDispatched('refresh-payments')->assertDispatched('refresh-installments');
            $payment = $record->payments()->sole();
            $this->assertSame(40.0, (float) $payment->amount);
            Livewire::test($payments, ['ownerRecord' => $record, 'pageClass' => $view])->assertCanSeeTableRecords([$payment]);
            $page->dispatch('refresh-payments');
            $this->assertSame(160.0, $page->instance()->accountDetails()['balance']);
            $this->assertSame(40.0, $page->instance()->accountDetails()['settled']);
        }
        $this->assertDatabaseCount('cash_movements', 2);
    }

    public function test_attachment_widget_uploads_previews_edits_and_soft_deletes_owned_files(): void
    {
        $record = $this->title();
        $this->authenticate();
        $widget = $this->widget($record)->call('openUpload')->set('description', 'Comprovante inicial')
            ->set('files', [UploadedFile::fake()->create('comprovante.pdf', 2, 'application/pdf')])->call('uploadAttachments')->assertHasNoErrors();
        $attachment = $record->currentAttachments()->sole();
        Storage::disk('local')->assertExists($attachment->path);
        $widget->assertSee('comprovante.pdf')->assertSee('Versão 1')->set('attachmentSearch', 'arquivo inexistente')->assertDontSee('comprovante.pdf')->set('attachmentSearch', '')
            ->assertSet('files', [])->call('viewAttachment', $attachment->id)->assertDispatched('open-modal')
            ->call('editDescription', $attachment->id)->set('editingDescription', 'Comprovante revisado')->call('saveDescription')->assertHasNoErrors();
        $this->assertSame('Comprovante revisado', $attachment->fresh()->description);
        $widget->call('deleteAttachment', $attachment->id)->assertDontSee('comprovante.pdf');
        $this->assertSoftDeleted('attachments', ['id' => $attachment->id]);
        Storage::disk('local')->assertExists($attachment->path);
    }

    public function test_financial_attachment_routes_authorize_the_parent_without_a_filament_tenant_in_the_url(): void
    {
        $record = $this->title(false);
        $this->authenticate();
        $attachment = app(AttachmentService::class)->upload($record, UploadedFile::fake()->create('documento.pdf', 2, 'application/pdf'), 'generic');
        Filament::setTenant(null);
        Filament::setCurrentPanel(null);
        $this->get($attachment->url)->assertOk()->assertDownload('documento.pdf');
        $this->get($attachment->preview_url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->teams->endBpo($this->owner, $this->company, $this->engagementId);
        $this->get($attachment->url)->assertForbidden();
        $this->get($attachment->preview_url)->assertForbidden();
    }

    public function test_read_only_access_hides_mutations_and_rechecks_upload_requests(): void
    {
        $record = $this->title();
        $this->authenticate();
        $role = $this->teams->saveRole($this->owner, $this->company, ['name' => 'Consulta', 'permissions' => ['account_payables.view' => 'company'], 'panels' => ['bpo']]);
        $this->teams->assign($this->owner, $this->company, $this->user, [$role->id], $this->engagementId);
        $this->get(AccountPayableResource::getUrl('view', ['record' => $record], panel: 'bpo', tenant: $this->company))->assertOk()->assertDontSee('Editar lançamento');
        $this->widget($record)->assertSee('Consulta de documentos')->assertDontSee('Adicionar arquivos')->call('openUpload')->assertForbidden();
        $this->widget($record)->call('uploadAttachments')->assertForbidden();
        Livewire::test(PayableInstallments::class, ['ownerRecord' => $record, 'pageClass' => ViewPayable::class])
            ->assertCanSeeTableRecords($record->installments)->assertActionHidden(TestAction::make('register_payment')->table($record->installments->first()));
    }

    public function test_widget_cannot_operate_on_an_attachment_belonging_to_a_different_record(): void
    {
        $first = $this->title();
        $second = $this->title();
        $this->authenticate();
        $foreign = app(AttachmentService::class)->upload($second, UploadedFile::fake()->create('outra-conta.pdf', 2, 'application/pdf'), 'generic');
        $this->widget($first)->call('deleteAttachment', $foreign->id)->assertNotFound();
        $this->widget($first)->call('viewAttachment', $foreign->id)->assertNotFound();
        $this->assertNotSoftDeleted('attachments', ['id' => $foreign->id]);
    }

    public function test_upload_validates_type_mime_size_and_preserves_single_latest_versioning(): void
    {
        $record = $this->title();
        $this->authenticate();
        $this->widget($record, ['allowedTypes' => ['fiscal_document']])->set('attachmentType', 'generic')
            ->set('files', [UploadedFile::fake()->create('arquivo.txt', 1, 'text/plain')])->call('uploadAttachments')->assertHasErrors('attachmentType');
        $this->widget($record, ['allowedTypes' => ['fiscal_document']])->set('files', [UploadedFile::fake()->create('imagem.jpg', 1, 'image/jpeg')])
            ->call('uploadAttachments')->assertHasErrors('files.0');
        config(['attachments.types.fiscal_document.max_size' => 1]);
        $this->widget($record, ['allowedTypes' => ['fiscal_document']])->set('files', [UploadedFile::fake()->create('grande.pdf', 2, 'application/pdf')])
            ->call('uploadAttachments')->assertHasErrors('files.0');
        $this->assertDatabaseCount('attachments', 0);
        $widget = $this->widget($record, ['heading' => 'Documentos contratuais', 'allowedTypes' => ['contract']])->assertSee('Documentos contratuais');
        $widget->set('files', [UploadedFile::fake()->create('contrato-v1.pdf', 1, 'application/pdf')])->call('uploadAttachments')->assertHasNoErrors();
        $widget->set('files', [UploadedFile::fake()->create('contrato-v2.pdf', 1, 'application/pdf')])->call('uploadAttachments')->assertHasNoErrors();
        $this->assertSame(2, $record->attachments()->count());
        $this->assertSame('contrato-v2.pdf', $record->currentAttachments()->sole()->original_name);
        $this->assertSame(2, $record->currentAttachments()->sole()->version);
    }

    public function test_disabled_widget_mutations_and_revoked_components_fail_closed(): void
    {
        $record = $this->title();
        $this->authenticate();
        $attachment = app(AttachmentService::class)->upload($record, UploadedFile::fake()->create('evidencia.pdf', 1, 'application/pdf'), 'generic');
        $this->widget($record, ['uploadsEnabled' => false, 'deletionsEnabled' => false])->assertDontSee('Adicionar arquivos')
            ->call('deleteAttachment', $attachment->id)->assertForbidden();
        $widget = $this->widget($record);
        $relation = Livewire::test(PayablePayments::class, ['ownerRecord' => $record, 'pageClass' => ViewPayable::class]);
        $this->teams->endBpo($this->owner, $this->company, $this->engagementId);
        $widget->call('viewAttachment', $attachment->id)->assertForbidden();
        $relation->call('$refresh')->assertForbidden();
    }

    public static function paymentTypes(): array
    {
        return ['pagamento' => [true], 'recebimento' => [false]];
    }

    #[DataProvider('paymentTypes')]
    public function test_payment_menu_shows_named_actions_and_editing_preserves_money_and_refreshes_relationships(bool $payable): void
    {
        $record = $this->title($payable);
        $account = FinancialAccount::create(['company_id' => $this->company->id, 'name' => 'Caixa dos recebimentos', 'type' => 'cash', 'is_active' => true, 'created_by' => $this->user->id]);
        $this->authenticate();
        $service = app($payable ? AccountPayableService::class : AccountReceivableService::class);
        $payment = $service->registerInstallmentPayment($record->installments()->firstOrFail(), 40, today()->toDateString(), ['financial_account_id' => $account->id]);
        $this->assertNotNull($payment, $service->getMessage());
        $baseManager = $payable
            ? PaymentsRelationManager::class
            : \App\Filament\Clusters\Financial\Resources\AccountReceivables\RelationManagers\PaymentsRelationManager::class;
        $basePage = Livewire::test($baseManager, ['ownerRecord' => $record, 'pageClass' => $payable ? ViewPayable::class : ViewReceivable::class]);
        $baseGroup = $basePage->instance()->getTable()->getRecordActions()[0];
        $this->assertSame(Heroicon::Bars3, $baseGroup->getIcon());
        foreach ($baseGroup->getActions() as $action) {
            $this->assertInstanceOf(Action::class, $action);
            $this->assertFalse($action->isIconButton());
            $this->assertFalse($action->isLabelHidden());
        }
        $page = Livewire::test($payable ? PayablePayments::class : ReceivablePayments::class, ['ownerRecord' => $record, 'pageClass' => $payable ? ViewPayable::class : ViewReceivable::class]);
        $group = $page->instance()->getTable()->getRecordActions()[0];
        $this->assertSame(Heroicon::Bars3, $group->getIcon());
        $this->assertCount(2, $group->getActions());
        foreach ($group->getActions() as $action) {
            $this->assertInstanceOf(Action::class, $action);
        }
        foreach ($group->getFlatActions() as $action) {
            $this->assertFalse($action->isIconButton());
            $this->assertFalse($action->isLabelHidden());
        }
        $page->assertSee($payable ? 'Editar pagamento' : 'Editar recebimento')->assertSee($payable ? 'Excluir pagamento' : 'Excluir recebimento')
            ->mountAction(TestAction::make('edit_payment')->table($payment))
            ->assertActionDataSet(['amount' => '40,00', 'financial_account_id' => $account->id]);
        $modal = $page->getMountedActionModalHtml();
        $this->assertStringContainsString('Encargos e descontos', $modal);
        $this->assertStringContainsString('Detalhes', $modal);
        $this->assertStringContainsString('Salvar alterações', $modal);
        $page->callMountedAction()->assertHasNoActionErrors()->assertDispatched('refresh-payments')->assertDispatched('refresh-installments');
        $this->assertSame(40.0, (float) $payment->fresh()->amount);
        $this->assertSame(40.0, (float) CashMovement::sole()->amount);
        $page->callAction(TestAction::make('edit_payment')->table($payment->fresh()), data: ['amount' => '45,50', 'description' => 'Recebimento corrigido'])
            ->assertHasNoActionErrors();
        $this->assertSame(45.5, (float) $payment->fresh()->amount);
        $this->assertSame(45.5, (float) CashMovement::sole()->amount);
        $this->assertSame('Recebimento corrigido', $payment->fresh()->description);
        $this->assertDatabaseCount('cash_movements', 1);
    }
}
