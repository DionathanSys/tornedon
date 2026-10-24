<?php

namespace Tests\Feature\Services\Financial;

use App\Filament\Clusters\Financial\Resources\AccountPayableInstallments\Pages\ListAccountPayableInstallments;
use App\Filament\Clusters\Financial\Resources\AccountPayables\AccountPayableResource;
use App\Filament\Clusters\Financial\Resources\AccountPayables\Pages\CreateAccountPayable;
use App\Filament\Clusters\Financial\Resources\AccountPayables\Pages\ViewAccountPayable;
use App\Filament\Clusters\Financial\Resources\AccountPayables\RelationManagers\InstallmentsRelationManager;
use App\Filament\Clusters\Financial\Resources\AccountReceivableInstallments\Pages\ListAccountReceivableInstallments;
use App\Filament\Clusters\Financial\Resources\AccountReceivables\AccountReceivableResource;
use App\Filament\Clusters\Financial\Resources\AccountReceivables\Pages\CreateAccountReceivable;
use App\Filament\Clusters\Financial\Resources\AccountReceivables\Pages\ViewAccountReceivable;
use App\Filament\Operation\OperationResourceSupport;
use App\Models\AccountPayable;
use App\Models\AccountReceivable;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\Invoice;
use App\Models\Partner;
use App\Models\User;
use App\Services\AccountPayable\AccountPayableService;
use App\Services\AccountReceivable\AccountReceivableService;
use App\Services\Financial\IgnoreFinancialInstallmentsService;
use Filament\Facades\Filament;
use Filament\Tables\Enums\ColumnManagerLayout;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class FinancialAccountWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private FinancialAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->company = Company::factory()->create();
        $this->user->companies()->attach($this->company, ['role' => 'member', 'is_active' => true]);
        $this->actingAs($this->user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->company);
        $this->bank = FinancialAccount::create(['company_id' => $this->company->id, 'name' => 'Banco teste', 'type' => 'bank', 'is_active' => true]);
    }

    public static function domains(): array
    {
        return [['payable'], ['receivable']];
    }

    private function account(string $domain): array
    {
        $category = FinancialCategory::create(['company_id' => $this->company->id, 'name' => 'Categoria', 'allow_payable' => true, 'allow_receivable' => true, 'allow_cash_movement' => true, 'is_active' => true]);
        $service = app($domain === 'payable' ? AccountPayableService::class : AccountReceivableService::class);
        $account = $service->create(['company_id' => $this->company->id, 'manual_counterparty_name' => 'Contraparte original',
            'due_date' => today()->subMonth()->toDateString(), 'due_amount' => 100, 'installment_count' => 2, 'financial_category_id' => $category->id,
        ], $this->user->id);
        $this->assertNotNull($account, $service->getMessageUser());

        return [$service, $account];
    }

    private function grantConsideration(): void
    {
        $this->user->companyAccesses()->create(['company_id' => $this->company->id,
            'permissions' => ['account_payables.access', 'account_receivables.access', 'account_payables.change_status_without_movement', 'account_receivables.change_status_without_movement'],
            'panels' => ['admin' => true], 'navigation' => [],
        ]);
    }

    #[DataProvider('domains')]
    public function test_create_redirects_to_view_and_due_date_is_only_available_on_creation(string $domain): void
    {
        $create = $domain === 'payable'
            ? CreateAccountPayable::class
            : CreateAccountReceivable::class;
        $resource = $domain === 'payable'
            ? AccountPayableResource::class
            : AccountReceivableResource::class;
        $page = Livewire::test($create)->fillForm([
            'is_manual_counterparty' => true, 'manual_counterparty_name' => 'Conta criada pela tela',
            'due_date' => today()->toDateString(), 'due_amount' => '100,00', 'installment_count' => 1,
        ])->call('create')->assertHasNoFormErrors();
        $account = ($domain === 'payable' ? AccountPayable::class : AccountReceivable::class)::query()->sole();
        $page->assertRedirect($resource::getUrl('view', ['record' => $account]));
        $edit = $resource::getPages()['edit']->getPage();
        Livewire::test($edit, ['record' => $account->id])->assertFormFieldIsHidden('due_date');
    }

    #[DataProvider('domains')]
    public function test_operational_balance_and_period_use_open_installments_instead_of_header_date(string $domain): void
    {
        [$service, $account] = $this->account($domain);
        $installments = $account->installments()->orderBy('id')->get();
        $this->assertNotNull($service->registerInstallmentPayment($installments[0], 50, today()->toDateString(), ['financial_account_id' => $this->bank->id]));
        $this->assertNotNull($service->updateInstallment($installments[1], ['due_date' => '2027-02-15']));
        $this->assertNotNull($service->registerInstallmentPayment($installments[1], 20, today()->toDateString(), ['financial_account_id' => $this->bank->id]));
        Filament::setCurrentPanel(Filament::getPanel('operation'));
        $module = $domain === 'payable' ? 'account_payables' : 'account_receivables';
        $pageClass = OperationResourceSupport::resource($module)::getPages()['index']->getPage();
        $page = Livewire::test($pageClass)->set('dateFilter.from', '2027-02-01')->set('dateFilter.until', '2027-02-28')->call('applyDateFilters')->assertHasNoErrors();
        $this->assertSame(1, $page->instance()->records->total());
        $this->assertSame('Saldo em aberto: R$ 30,00', $page->instance()->records->items()[0]['amount']);
        $page->call('openRegisterPayment', $account->id)->assertSet('paymentData.amount', '30,00');
        $editClass = OperationResourceSupport::resource($module)::getPages()['edit']->getPage();
        Livewire::test($editClass, ['record' => $account->id])->call('save')->assertForbidden();
    }

    #[DataProvider('domains')]
    public function test_account_list_can_sort_counterparty_and_display_aggregated_open_balance(string $domain): void
    {
        [, $account] = $this->account($domain);
        $resource = $domain === 'payable'
            ? AccountPayableResource::class
            : AccountReceivableResource::class;
        $page = Livewire::test($resource::getPages()['index']->getPage())->sortTable('counterparty_label')->assertSuccessful();
        $page->assertCanSeeTableRecords([$account]);
        $record = $page->instance()->getTableRecords()->first();
        $this->assertSame(100.0, (float) $record->considered_balance / 100);
    }

    #[DataProvider('domains')]
    public function test_tab_counts_follow_status_filters_and_column_manager_is_a_four_column_modal(string $domain): void
    {
        [, $account] = $this->account($domain);
        $pageClass = $domain === 'payable' ? ListAccountPayableInstallments::class : ListAccountReceivableInstallments::class;
        $page = Livewire::test($pageClass)->removeTableFilters();
        $page->filterTable('status', ['received', 'paid']);
        $this->assertSame('0', $page->instance()->getTabs()['all']->getBadge());
        $page->removeTableFilters();
        $this->assertSame('2', $page->instance()->getTabs()['all']->getBadge());
        $table = $page->instance()->getTable();
        $this->assertSame(ColumnManagerLayout::Modal, $table->getColumnManagerLayout());
        $this->assertSame(4, $table->getColumnManagerColumns()['lg']);
        foreach (['due_amount', $domain === 'payable' ? 'paid_amount' : 'received_amount', 'balance_amount'] as $column) {
            $this->assertFalse($table->getColumn($column)->isToggledHiddenByDefault());
        }
    }

    #[DataProvider('domains')]
    public function test_any_existing_payment_blocks_all_header_changes_but_not_installment_actions(string $domain): void
    {
        [$service, $account] = $this->account($domain);
        $installment = $account->installments()->first();
        $payment = $service->registerInstallmentPayment($installment, 20, today()->toDateString(), ['financial_account_id' => $this->bank->id]);
        $this->assertNotNull($payment, $service->getMessageUser());
        $before = $account->fresh()->getRawOriginal();
        $this->assertNull($service->update($account, ['description' => 'Alteração proibida'], $this->user->id));
        $this->assertArrayHasKey('account', $service->getErrors());
        $this->assertSame($before, $account->fresh()->getRawOriginal());
        $this->assertNotNull($service->updateInstallment($installment, ['notes' => 'Anotação permitida']));

        $view = $domain === 'payable' ? ViewAccountPayable::class : ViewAccountReceivable::class;
        Livewire::test($view, ['record' => $account->id])->assertSuccessful()->assertActionHidden('edit');
        $relation = $domain === 'payable'
            ? InstallmentsRelationManager::class
            : \App\Filament\Clusters\Financial\Resources\AccountReceivables\RelationManagers\InstallmentsRelationManager::class;
        $component = Livewire::test($relation, ['ownerRecord' => $account->fresh(), 'pageClass' => $view])->assertSuccessful();
        $this->assertFalse($component->instance()->isReadOnly());
        $component->assertTableActionEnabled('register_payment', $installment->fresh());
        $component->mountTableAction('register_payment', $installment->fresh())->assertTableActionDataSet(['amount' => '30,00']);
    }

    #[DataProvider('domains')]
    public function test_counterparty_correction_requires_reason_and_active_company_membership(string $domain): void
    {
        [$service, $account] = $this->account($domain);
        $field = $domain === 'payable' ? 'supplier_id' : 'customer_id';
        $partner = Partner::factory()->create();
        $payload = [$field => $partner->id, 'manual_counterparty_name' => null, 'counterparty_change_reason' => 'Correção do cadastro'];
        $this->assertNull($service->update($account, $payload, $this->user->id));
        $partner->companies()->attach($this->company, ['is_active' => true, 'type' => '["customer","supplier"]']);
        $this->assertNull($service->update($account, [...$payload, 'counterparty_change_reason' => ''], $this->user->id));
        $this->assertArrayHasKey('counterparty_change_reason', $service->getErrors());
        $updated = $service->update($account, $payload, $this->user->id);
        $this->assertNotNull($updated, $service->getMessageUser());
        $this->assertEquals($partner->id, $updated->{$field});
        $this->assertDatabaseHas('audit_entries', ['auditable_id' => $account->id, 'event' => 'account_'.$domain.'.updated']);
        $this->assertNotNull($service->update($updated, ['description' => 'Somente descrição'], $this->user->id));
        $this->assertEquals($partner->id, $account->fresh()->{$field});
    }

    public function test_origin_prevents_counterparty_reassignment_even_without_payments(): void
    {
        [$service, $account] = $this->account('receivable');
        $partner = Partner::factory()->create();
        $invoice = Invoice::create(['company_id' => $this->company->id, 'customer_id' => $partner->id, 'invoice_number' => 'ORIGEM-1', 'invoice_date' => today(), 'status' => 'pending']);
        $account->update(['invoice_id' => $invoice->id]);
        $this->assertNull($service->update($account, ['manual_counterparty_name' => 'Outro parceiro', 'counterparty_change_reason' => 'Tentativa de troca'], $this->user->id));
        $this->assertArrayHasKey('customer_id', $service->getErrors());
        $this->assertSame('Contraparte original', $account->fresh()->manual_counterparty_name);
    }

    #[DataProvider('domains')]
    public function test_ignoring_and_restoring_via_bulk_action_preserves_financial_history_and_filters_counts(string $domain): void
    {
        $this->grantConsideration();
        [$service, $account] = $this->account($domain);
        $installment = $account->installments()->orderBy('id')->first();
        $this->assertNotNull($service->registerInstallmentPayment($installment, 20, today()->toDateString(), ['financial_account_id' => $this->bank->id]));
        $before = $installment->fresh()->getRawOriginal();
        $pageClass = $domain === 'payable' ? ListAccountPayableInstallments::class : ListAccountReceivableInstallments::class;
        $page = Livewire::test($pageClass)->removeTableFilters();
        $page->assertCanSeeTableRecords([$installment]);
        $this->assertSame('2', $page->instance()->getTabs()['pending']->getBadge());
        $page->callTableBulkAction('ignore_financial', [$installment], ['reason' => 'Anterior à data de corte'])->assertHasNoTableBulkActionErrors();
        $this->assertNotNull($installment->fresh()->ignored_at);
        $this->assertSame('1', $page->instance()->getTabs()['pending']->getBadge());
        $this->assertNull($service->registerInstallmentPayment($installment, 10, today()->toDateString(), ['financial_account_id' => $this->bank->id]));
        $after = $installment->fresh()->getRawOriginal();
        foreach (['ignored_at', 'ignored_by', 'ignored_reason', 'updated_at'] as $field) {
            unset($before[$field], $after[$field]);
        }
        $this->assertSame($before, $after);
        $this->assertDatabaseCount('cash_movements', 1);
        $page->set('activeTab', 'ignored')->callTableBulkAction('restore_consideration', [$installment], ['reason' => 'Revisão da data de corte'])->assertHasNoTableBulkActionErrors();
        $this->assertNull($installment->fresh()->ignored_at);
        $this->assertSame('2', $page->instance()->getTabs()['pending']->getBadge());
        $this->assertDatabaseHas('audit_entries', ['event' => 'financial_installment.restored', 'actor_user_id' => $this->user->id]);
        $page->searchTable('inexistente');
        $this->assertSame('0', $page->instance()->getTabs()['pending']->getBadge());
    }

    #[DataProvider('domains')]
    public function test_ignored_installments_are_not_paid_automatically(string $domain): void
    {
        $this->grantConsideration();
        [, $account] = $this->account($domain);
        $installment = $account->installments()->first();
        $account->update($domain === 'payable' ? ['is_effective' => true, 'auto_register_payment_on_due_date' => true, 'auto_payment_financial_account_id' => $this->bank->id]
            : ['auto_register_receipt_on_due_date' => true, 'auto_receipt_financial_account_id' => $this->bank->id]);
        app(IgnoreFinancialInstallmentsService::class)->change(new Collection([$installment]), true, 'Data de corte inicial', $this->user, $this->company, 'admin');
        $this->artisan($domain === 'payable' ? 'account-payables:process-auto-payments' : 'account-receivables:process-auto-receipts', ['--date' => $installment->due_date->toDateString()])->assertSuccessful();
        $this->assertSame(0, $installment->payments()->count());
        $this->assertSame(50.0, $installment->fresh()->balance_amount);
    }

    #[DataProvider('domains')]
    public function test_account_view_can_ignore_and_restore_all_open_installments(string $domain): void
    {
        $this->grantConsideration();
        [, $account] = $this->account($domain);
        $pageClass = $domain === 'payable' ? ViewAccountPayable::class : ViewAccountReceivable::class;
        $page = Livewire::test($pageClass, ['record' => $account->id]);
        $page->callAction('ignore_financial', ['reason' => 'Implantação do módulo'])->assertHasNoActionErrors();
        $this->assertSame(0, $account->installments()->open()->count());
        $this->assertSame(2, $account->installments()->whereNotNull('ignored_at')->count());
        $page->callAction('restore_consideration', ['reason' => 'Revisão da implantação'])->assertHasNoActionErrors();
        $this->assertSame(2, $account->installments()->open()->count());
        $this->assertDatabaseCount('cash_movements', 0);
    }

    #[DataProvider('domains')]
    public function test_ignoring_a_forged_cross_company_selection_rolls_back_everything(string $domain): void
    {
        $this->grantConsideration();
        [, $account] = $this->account($domain);
        $local = $account->installments()->first();
        $foreign = $local->replicate();
        $foreign->company_id = Company::factory()->create()->id;
        $foreign->save();
        $foreign->company_id = $this->company->id;
        try {
            app(IgnoreFinancialInstallmentsService::class)->change(new Collection([$local, $foreign]), true, 'Data de corte inicial', $this->user, $this->company, 'admin');
            $this->fail('Deveria rejeitar a empresa persistida divergente.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertNull($local->fresh()->ignored_at);
        $this->assertNull($foreign->fresh()->ignored_at);
    }
}
