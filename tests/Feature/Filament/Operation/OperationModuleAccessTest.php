<?php

namespace Tests\Feature\Filament\Operation;

use App\Enum\User\ManagementRole;
use App\Filament\Management\Resources\Users\Pages\EditUser;
use App\Filament\Operation\OperationModules;
use App\Filament\Operation\Pages\CommercialDashboard;
use App\Filament\Operation\Pages\OperationDashboard;
use App\Filament\Operation\Pages\Requisitions\RequisitionList;
use App\Filament\Operation\Pages\ServiceOrders\ServiceOrderQueue;
use App\Filament\Operation\Resources\AccountPayables\AccountPayableResource;
use App\Filament\Operation\Resources\AccountReceivables\AccountReceivableResource;
use App\Filament\Operation\Resources\CashMovements\CashMovementResource;
use App\Filament\Operation\Resources\ProductionRequests\Pages\ListProductionRequests;
use App\Filament\Operation\Resources\ProductionRequests\ProductionRequestResource;
use App\Filament\Operation\Widgets\AccountsChart;
use App\Filament\Operation\Widgets\RevenueChart;
use App\Livewire\OperationMenu;
use App\Livewire\OperationRecordCreator;
use App\Models\AccountPayable;
use App\Models\AccountReceivable;
use App\Models\CashMovement;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\ProductionRequest;
use App\Models\User;
use App\Services\Access\UserAccessConfigurationService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OperationModuleAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_migrated_list_create_and_report_pages_use_the_operation_layout(): void
    {
        [, $company] = $this->authenticateTenant();

        foreach ([ProductionRequestResource::class, AccountReceivableResource::class, AccountPayableResource::class, CashMovementResource::class] as $resource) {
            foreach (['index', 'create'] as $page) {
                $this->get($resource::getUrl($page, panel: 'operation', tenant: $company))
                    ->assertOk()
                    ->assertSee('operation-ui', false)
                    ->assertSee('operation-bar-button', false)
                    ->assertDontSee('pr-mob-', false)
                    ->assertDontSee('shop-bottom-navigation', false);
            }
        }

        $this->get(ProductionRequestResource::getUrl('open-products-report', panel: 'operation', tenant: $company))->assertOk();
        $this->assertNull(Filament::getPanel('shop', isStrict: false));
    }

    public function test_commercial_indicators_have_their_own_dashboard(): void
    {
        [, $company] = $this->authenticateTenant();

        $this->get(CommercialDashboard::getUrl(tenant: $company))
            ->assertOk()
            ->assertSee('Pedidos em Aberto');
        Livewire::test(AccountsChart::class)->assertSee('Contas a Receber vs a Pagar');
        $this->get(OperationDashboard::getUrl(tenant: $company))
            ->assertOk()
            ->assertSee('OS no dia')
            ->assertDontSee('Pedidos em Aberto');
    }

    public function test_migrated_lists_and_edit_pages_are_scoped_to_the_current_company(): void
    {
        [$user, $company] = $this->authenticateTenant();
        $other = Company::factory()->create();
        foreach ([
            ProductionRequestResource::class => ProductionRequest::class,
            AccountReceivableResource::class => AccountReceivable::class,
            AccountPayableResource::class => AccountPayable::class,
            CashMovementResource::class => CashMovement::class,
        ] as $resource => $model) {
            $records = [];
            foreach ([$company, $other] as $owner) {
                Filament::setTenant($owner);
                $label = 'Registro-'.$owner->id.'-'.class_basename($model);
                $data = [
                    'company_id' => $owner->id, 'manual_counterparty_name' => $label,
                    'status' => 'pending', 'due_date' => today(), 'due_amount' => 100, 'paid_amount' => 0,
                ];
                if ($model === ProductionRequest::class) {
                    $data = ['company_id' => $owner->id, 'manual_counterparty_name' => $label, 'status' => 'open', 'order_date' => today(), 'created_by' => $user->id];
                }
                if ($model === AccountPayable::class) {
                    $data['sequence_number'] = '01';
                }
                if ($model === CashMovement::class) {
                    $account = FinancialAccount::create(['company_id' => $owner->id, 'name' => $label, 'type' => 'cash', 'is_active' => true]);
                    $data = ['company_id' => $owner->id, 'financial_account_id' => $account->id, 'direction' => 'inflow', 'transaction_date' => today(), 'amount' => 100, 'description' => $label];
                }
                $records[] = $model::create($data);
                if ($model === AccountPayable::class || $model === AccountReceivable::class) {
                    end($records)->installments()->create([
                        'company_id' => $owner->id, 'sequence_number' => '01', 'status' => 'pending',
                        'due_date' => today(), 'original_amount' => 100, 'due_amount' => 100, 'balance_amount' => 100,
                    ]);
                }
            }
            Filament::setTenant($company);
            $this->get($resource::getUrl(tenant: $company))
                ->assertOk()
                ->assertSee('Registro-'.$company->id.'-'.class_basename($model))
                ->assertDontSee('Registro-'.$other->id.'-'.class_basename($model));
            $this->get($resource::getUrl('edit', ['record' => $records[0]], tenant: $company))
                ->assertOk()->assertSee('operation-bar-button', false)->assertDontSee('pr-mob-', false);
            $this->get($resource::getUrl('edit', ['record' => $records[1]], tenant: $company))->assertNotFound();
        }
    }

    public function test_navigation_has_four_ordered_shortcuts_and_puts_the_rest_in_menu(): void
    {
        [$user, $company] = $this->authenticateTenant();
        $this->configure($user, $company, array_keys(OperationModules::permissions()), [
            'production_requests', 'requisitions', 'account_receivables', 'service_orders',
            'account_payables', 'cash_movements', 'commercial_dashboard', 'home',
        ]);

        $navigation = OperationModules::navigation();
        $this->assertSame(['production_requests', 'requisitions', 'account_receivables', 'service_orders'], array_column(array_slice($navigation, 0, 4), 'key'));
        $this->get(OperationDashboard::getUrl(tenant: $company))
            ->assertOk()->assertSee('--operation-columns: 5', false);
        Livewire::test(OperationMenu::class)
            ->assertSee('Pagar')->assertSee('Caixa')->assertSee('Produção e financeiro')
            ->assertDontSee('Pedidos')->assertDontSee('Receber');
    }

    public function test_denied_modules_are_hidden_and_block_urls_and_livewire_calls(): void
    {
        [$user, $company] = $this->authenticateTenant();
        $this->configure($user, $company, ['requisitions.access']);

        $this->assertSame(['home', 'requisitions'], array_column(OperationModules::navigation(), 'key'));
        $this->get(ServiceOrderQueue::getUrl(tenant: $company))->assertForbidden();
        $this->get(ProductionRequestResource::getUrl(tenant: $company))->assertForbidden();
        $this->get(AccountPayableResource::getUrl('create', tenant: $company))->assertForbidden();
        $this->get(CommercialDashboard::getUrl(tenant: $company))->assertForbidden();
        Livewire::test(ListProductionRequests::class)->assertForbidden();
        Livewire::test(RevenueChart::class)->assertForbidden();
        Livewire::test(OperationRecordCreator::class)->call('open', 'service-order')->assertForbidden();
        Livewire::test(OperationRecordCreator::class)->call('create')->assertForbidden();
        Livewire::test(OperationMenu::class)->assertDontSee('Nova ordem')->assertSee('Nova requisição');
        $this->get(RequisitionList::getUrl(tenant: $company))->assertOk();
    }

    public function test_revoking_a_module_blocks_an_already_mounted_component(): void
    {
        [$user, $company] = $this->authenticateTenant();
        $page = Livewire::test(ListProductionRequests::class);
        $creator = Livewire::test(OperationRecordCreator::class)->call('open', 'service-order');

        $this->configure($user, $company, []);

        $page->call('deleteProductionRequest', 1)->assertForbidden();
        $creator->call('create')->assertForbidden();
        $this->assertSame(0, ProductionRequest::query()->count());
    }

    public function test_access_and_navigation_differ_between_companies_and_panel_denial_is_enforced(): void
    {
        [$user, $company] = $this->authenticateTenant();
        $other = Company::factory()->create();
        $user->companies()->attach($other, ['role' => 'member', 'is_active' => true]);
        $this->configure($user, $company, ['production_requests.access'], ['production_requests', 'home']);
        $this->configure($user, $other, ['cash_movements.access'], ['cash_movements', 'home']);

        $this->assertSame(['production_requests', 'home'], array_column(OperationModules::navigation(), 'key'));
        Filament::setTenant($other);
        $this->assertSame(['cash_movements', 'home'], array_column(OperationModules::navigation(), 'key'));
        $this->get(CashMovementResource::getUrl(tenant: $other))->assertOk();
        $this->get(ProductionRequestResource::getUrl(tenant: $other))->assertForbidden();

        $user->companyAccesses()->where('company_id', $other->id)->update(['panels' => ['operation' => false]]);
        $this->get(OperationDashboard::getUrl(tenant: $other))->assertForbidden();
        $this->get(CashMovementResource::getUrl(tenant: $other))->assertForbidden();
        $this->assertFalse($user->getTenants(Filament::getPanel('operation'))->contains('id', $other->id));
    }

    public function test_management_can_save_permissions_and_order_without_changing_other_panel_settings(): void
    {
        [$user, $company] = $this->authenticateTenant();
        $user->companyAccesses()->create([
            'company_id' => $company->id,
            'permissions' => [], 'panels' => ['admin' => false], 'navigation' => ['admin' => ['customers']],
        ]);
        $admin = User::factory()->create(['management_role' => ManagementRole::MANAGEMENT_ADMIN]);
        $this->actingAs($admin);
        Filament::setCurrentPanel('management');
        Filament::setTenant(null);

        Livewire::test(EditUser::class, ['record' => $user->id])
            ->fillForm([
                'company_access' => [[
                    'company_id' => $company->id, 'operation_enabled' => true,
                    'permissions' => ['production_requests.access'],
                    'order' => [['module' => 'production_requests'], ['module' => 'home']],
                ]],
            ])
            ->call('save')->assertHasNoFormErrors();

        $settings = $user->companyAccesses()->firstOrFail();
        $this->assertSame(['production_requests.access'], $settings->permissions);
        $this->assertSame(['production_requests', 'home'], $settings->navigation['operation']);
        $this->assertFalse($settings->panels['admin']);
        $this->assertSame(['customers'], $settings->navigation['admin']);
    }

    public function test_management_rejects_settings_for_an_unlinked_company(): void
    {
        [$user, $company] = $this->authenticateTenant();
        $other = Company::factory()->create();
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);
        Filament::setCurrentPanel('management');
        Filament::setTenant(null);

        Livewire::test(EditUser::class, ['record' => $user->id])
            ->fillForm(['company_access' => [[
                'company_id' => $other->id, 'operation_enabled' => true,
                'permissions' => [], 'order' => [],
            ]]])->call('save')->assertHasFormErrors(['company_access.0.company_id']);

        $this->assertSame(0, $user->companyAccesses()->count());
        $this->assertTrue($user->canAccessTenant($company));
    }

    public function test_common_users_cannot_configure_permissions_in_management(): void
    {
        [$user] = $this->authenticateTenant();
        Filament::setCurrentPanel('management');
        Filament::setTenant(null);

        Livewire::test(EditUser::class, ['record' => $user->id])->assertForbidden();
    }

    public function test_removing_operation_configuration_preserves_other_panel_settings(): void
    {
        [$user, $company] = $this->authenticateTenant();
        $user->companyAccesses()->create([
            'company_id' => $company->id,
            'permissions' => [],
            'panels' => ['operation' => false, 'admin' => false],
            'navigation' => ['operation' => ['cash_movements'], 'admin' => ['customers']],
        ]);

        app(UserAccessConfigurationService::class)->save($user, []);

        $settings = $user->companyAccesses()->firstOrFail();
        $this->assertSame(['admin' => false], $settings->panels);
        $this->assertSame(['admin' => ['customers']], $settings->navigation);
        $this->assertTrue(OperationModules::allows('production_requests'));
    }

    private function authenticateTenant(): array
    {
        $user = User::factory()->create();
        $company = Company::factory()->create(['created_by' => $user->id]);
        $user->companies()->attach($company, ['role' => 'member', 'is_active' => true]);
        $this->actingAs($user);
        Filament::setCurrentPanel('operation');
        Filament::setTenant($company);

        return [$user, $company];
    }

    private function configure(User $user, Company $company, array $permissions, array $order = []): void
    {
        $user->companyAccesses()->updateOrCreate(['company_id' => $company->id], [
            'permissions' => $permissions, 'panels' => ['operation' => true], 'navigation' => ['operation' => $order],
        ]);
    }
}
