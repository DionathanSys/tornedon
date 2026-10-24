<?php

namespace Tests\Feature\Filament\Operation;

use App\Enum\Payment\Method;
use App\Filament\Operation\OperationResourceSupport as Support;
use App\Filament\Operation\Resources\ProductionRequests\Pages\EditProductionRequest;
use App\Livewire\OperationModuleCreator;
use App\Models\CardInstitution;
use App\Models\CashMovement;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\Product;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OperationResourceInteractionsTest extends TestCase
{
    use RefreshDatabase;

    public static function modules(): array
    {
        return array_map(fn ($module) => [$module], ['production_requests', 'account_receivables', 'account_payables', 'cash_movements']);
    }

    #[DataProvider('modules')]
    public function test_lists_paginate_all_records_and_combine_search_dates_and_tabs(string $module): void
    {
        [$user, $company, $account] = $this->authenticate();
        for ($i = 1; $i <= 81; $i++) {
            $this->record($module, $company, $account, 'REG-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT));
        }
        $other = Company::factory()->create();
        $this->record($module, $other, $account, 'FOREIGN');
        $pageClass = Support::resource($module)::getPages()['index']->getPage();
        $page = Livewire::test($pageClass)
            ->assertSee('operation-fab', false)
            ->assertSee('Paginação dos registros')
            ->assertDontSee('pr-mob-', false)
            ->assertDontSee('wire:confirm', false)
            ->assertDontSee('FOREIGN');
        $this->assertSame(81, $page->instance()->records->total());
        $this->assertCount(15, $page->instance()->records->items());
        $page->call('gotoPage', 6)->assertSee('REG-001');
        $page->set('search', 'REG-081')->assertSee('REG-081')->assertDontSee('REG-080');
        $this->assertSame(1, $page->instance()->records->total());
        $page->set('dateFilter.from', '2026-01-01')->set('dateFilter.until', '2026-01-31')->call('applyDateFilters')->assertHasNoErrors();
        $this->assertSame(0, $page->instance()->records->total());
        $page->call('clearDateFilters')->assertSee('REG-081');
        $page->call('setTab', 'closed');
        $this->assertSame(0, $page->instance()->records->total());
        $page->call('setTab', 'all');
        $this->assertSame(1, $page->instance()->records->total());
    }

    #[DataProvider('modules')]
    public function test_creation_modal_saves_through_services_and_uses_mary_toasts(string $module): void
    {
        [, $company, $account, $category] = $this->authenticate();
        $page = Livewire::test(OperationModuleCreator::class)
            ->call('open', $module)->assertSet('showModal', true)
            ->assertSee('modal-box', false)->assertDontSee('fi-fo-', false)
            ->set('data.manual_counterparty_name', 'Cliente modal')
            ->set('data.financial_category_id', $category->id)
            ->set('data.financial_account_id', $account->id)
            ->set('data.payment_method', Method::CASH->value)
            ->set('data.description', 'Movimento modal')
            ->set('data.due_amount', '300,00')->set('data.amount', '300,00')
            ->set('data.installment_count', 3)
            ->call('create')->assertHasNoErrors();
        $record = Support::query($module)->firstOrFail();
        $this->assertSame($company->id, $record->company_id);
        $this->assertStringContainsString('toast(', json_encode($page->effects));
        $this->assertEmpty(session('filament.notifications', []));
        $page->assertRedirect(Support::resource($module)::getUrl(str_starts_with($module, 'account_') ? 'view' : 'edit', ['record' => $record]));
        if (str_starts_with($module, 'account_')) {
            $this->assertSame(3, $record->installments()->count());
            $this->assertEquals(300, $record->due_amount);
        }
    }

    public function test_modal_rejects_foreign_options_invalid_amount_and_revoked_access(): void
    {
        [$user, $company] = $this->authenticate();
        $other = Company::factory()->create();
        $category = FinancialCategory::create(['company_id' => $other->id, 'name' => 'Categoria externa', 'allow_receivable' => true, 'is_active' => true]);
        $page = Livewire::test(OperationModuleCreator::class)->call('open', 'account_receivables')
            ->set('data.manual_counterparty_name', 'Cliente')
            ->set('data.due_amount', 'inválido')
            ->set('data.financial_category_id', $category->id)->call('create')
            ->assertHasErrors(['data.due_amount', 'data.financial_category_id']);
        $user->companyAccesses()->create(['company_id' => $company->id, 'permissions' => [], 'panels' => ['operation' => true], 'navigation' => []]);
        $page->call('create')->assertForbidden();
        $this->assertSame(0, Support::query('account_receivables')->count());
    }

    public static function accounts(): array
    {
        return [['account_receivables'], ['account_payables']];
    }

    #[DataProvider('accounts')]
    public function test_payments_use_a_modal_validate_fields_and_update_cash(string $module): void
    {
        [, , $account, $category] = $this->authenticate();
        Livewire::test(OperationModuleCreator::class)->call('open', $module)
            ->set('data.manual_counterparty_name', 'Conta teste')->set('data.due_amount', '100,00')
            ->set('data.financial_category_id', $category->id)->call('create')->assertHasNoErrors();
        $record = Support::query($module)->firstOrFail();
        $page = Livewire::test(Support::resource($module)::getPages()['index']->getPage())
            ->call('openRegisterPayment', $record->id)->assertSet('showPaymentForm', true)
            ->set('paymentData.amount', 'abc')->call('savePayment')->assertHasErrors(['paymentData.amount'])
            ->set('paymentData.amount', '100,00')->set('paymentData.financial_account_id', $account->id)
            ->call('savePayment')->assertHasNoErrors()->assertSet('showPaymentForm', false);
        $this->assertEquals(100, $record->fresh()->paid_amount);
        $this->assertSame(1, CashMovement::query()->count());
        $this->assertStringContainsString('toast(', json_encode($page->effects));
    }

    public function test_production_items_confirmation_and_delivery_preserve_business_rules(): void
    {
        [$user, $company, $account, $category] = $this->authenticate();
        Livewire::test(OperationModuleCreator::class)->call('open', 'production_requests')
            ->set('data.manual_counterparty_name', 'Cliente produção')
            ->set('data.financial_category_id', $category->id)->call('create')->assertHasNoErrors();
        $record = Support::query('production_requests')->firstOrFail();
        $product = Product::factory()->create(['company_id' => $company->id, 'is_active' => true, 'is_invoiceable' => true, 'created_by' => $user->id]);
        $page = Livewire::test(EditProductionRequest::class, ['record' => $record->id])
            ->assertDontSee('pr-mob-', false)->assertDontSee('fi-fo-', false)
            ->call('toggleItemForm')->assertSet('showItemForm', true)
            ->set('itemData.product_id', $product->id)->set('itemData.quantity', '2,000')
            ->call('saveItem')->assertHasNoErrors()->assertSet('showItemForm', false);
        $item = $record->items()->firstOrFail();
        $this->assertEquals(12, $item->unit_price);
        $page->call('requestItemDelete', $item->id)->assertSet('showDeleteItemModal', true)
            ->call('confirmItemDelete')->assertHasNoErrors();
        $this->assertSame(0, $record->items()->count());
        $page->call('toggleItemForm')->set('itemData.product_id', $product->id)
            ->set('itemData.quantity', '1,000')->call('saveItem')->assertHasNoErrors()
            ->call('deliver')->assertSet('showDeliverConfirmation', true)
            ->set('deliverData.mark_as_received', true)->set('deliverData.financial_account_id', $account->id)
            ->call('confirmDeliver')->assertHasNoErrors()->assertSet('showDeliverConfirmation', false);
        $this->assertSame('delivered', $record->fresh()->status->value);
        $this->assertNotNull($record->fresh()->account_receivable_id);
        $this->assertSame(1, CashMovement::query()->count());
    }

    public function test_card_and_automatic_receipt_settings_survive_the_new_production_form(): void
    {
        [, , $account, $category] = $this->authenticate();
        $institution = CardInstitution::create(['company_id' => Filament::getTenant()->id, 'name' => 'Stone', 'settlement_days' => 15]);
        Livewire::test(OperationModuleCreator::class)->call('open', 'production_requests')
            ->set('data.manual_counterparty_name', 'Cliente cartão')
            ->set('data.financial_category_id', $category->id)
            ->set('data.payment_method', Method::CREDIT_CARD->value)
            ->set('data.card_payment_profile_id', $institution->id)
            ->set('data.payment_date', '2026-10-01')
            ->set('data.auto_register_receipt_on_due_date', true)
            ->set('data.auto_receipt_financial_account_id', $account->id)
            ->call('create')->assertHasNoErrors();
        $record = Support::query('production_requests')->firstOrFail();
        $this->assertSame($institution->id, $record->additional_info['card_payment_profile_id']);
        $this->assertTrue($record->additional_info['auto_register_receipt_on_due_date']);
        Livewire::test(EditProductionRequest::class, ['record' => $record->id])
            ->call('save')->assertHasNoErrors();
        $this->assertSame($account->id, $record->fresh()->additional_info['auto_receipt_financial_account_id']);
        $this->assertSame('2026-10-01', $record->fresh()->additional_info['payment_date']);
    }

    #[DataProvider('accounts')]
    public function test_account_deletion_requires_the_contextual_confirmation(string $module): void
    {
        [, , , $category] = $this->authenticate();
        Livewire::test(OperationModuleCreator::class)->call('open', $module)
            ->set('data.manual_counterparty_name', 'Conta para excluir')->set('data.due_amount', '100,00')
            ->set('data.financial_category_id', $category->id)->call('create')->assertHasNoErrors();
        $record = Support::query($module)->firstOrFail();
        $pageClass = Support::resource($module)::getPages()['edit']->getPage();
        Livewire::test($pageClass, ['record' => $record->id])->call('deleteRecord')->assertForbidden();
        Livewire::test($pageClass, ['record' => $record->id])
            ->call('requestOperationConfirmation', 'deleteRecord')->assertSet('showConfirmation', true)
            ->call('confirmOperation')->assertHasNoErrors()->assertRedirect(Support::resource($module)::getUrl());
        $this->assertSame(0, Support::query($module)->count());
    }

    #[DataProvider('modules')]
    public function test_edit_pages_save_with_mary_forms_and_contextual_actions(string $module): void
    {
        [, , , $category] = $this->authenticate();
        Livewire::test(OperationModuleCreator::class)->call('open', $module)
            ->set('data.manual_counterparty_name', 'Cliente edição')->set('data.description', 'Antes')
            ->set('data.due_amount', '100,00')->set('data.amount', '100,00')
            ->set('data.financial_category_id', $category->id)->call('create')->assertHasNoErrors();
        $record = Support::query($module)->firstOrFail();
        $pageClass = Support::resource($module)::getPages()['edit']->getPage();
        $page = Livewire::test($pageClass, ['record' => $record->id])->assertDontSee('fi-fo-', false)
            ->assertSee('Ações do registro');
        $page->set($module === 'production_requests' ? 'data.payment_condition' : 'data.description', $module === 'production_requests' ? '30_dias' : 'Depois')
            ->call('save')->assertHasNoErrors();
        $this->assertSame($module === 'production_requests' ? '30_dias' : 'Depois', $module === 'production_requests' ? $record->fresh()->payment_condition?->value : $record->fresh()->description);
        $this->assertStringContainsString('toast(', json_encode($page->effects));
    }

    private function authenticate(): array
    {
        $user = User::factory()->create();
        $company = Company::factory()->create(['created_by' => $user->id]);
        $user->companies()->attach($company, ['role' => 'member', 'is_active' => true]);
        $this->actingAs($user);
        Filament::setCurrentPanel('operation');
        Filament::setTenant($company);
        $account = FinancialAccount::create(['company_id' => $company->id, 'name' => 'Caixa padrão', 'type' => 'cash', 'is_active' => true, 'is_default' => true]);
        $category = FinancialCategory::create(['company_id' => $company->id, 'name' => 'Categoria teste', 'allow_receivable' => true, 'allow_payable' => true, 'allow_cash_movement' => true, 'is_active' => true]);

        return [$user, $company, $account, $category];
    }

    private function record(string $module, Company $company, FinancialAccount $account, string $label): mixed
    {
        $tenant = Filament::getTenant();
        Filament::setTenant($company);
        $data = match ($module) {
            'production_requests' => ['number' => $label, 'manual_counterparty_name' => $label, 'order_date' => today(), 'status' => 'open'],
            'cash_movements' => ['description' => $label, 'financial_account_id' => $account->id, 'transaction_date' => today(), 'direction' => 'inflow', 'amount' => 100],
            default => ['manual_counterparty_name' => $label, 'description' => $label, 'document_number' => $label, 'due_date' => today(), 'status' => 'pending', 'due_amount' => 100, 'paid_amount' => 0],
        };
        if ($module === 'account_payables') {
            $data['sequence_number'] = '01';
        }
        $record = Support::definition($module)['model']::create([...$data, 'company_id' => $company->id]);
        if (str_starts_with($module, 'account_')) {
            $record->installments()->create([
                'company_id' => $company->id, 'sequence_number' => '01', 'status' => 'pending',
                'due_date' => today(), 'original_amount' => 100, 'due_amount' => 100, 'balance_amount' => 100,
            ]);
        }
        Filament::setTenant($tenant);

        return $record;
    }
}
