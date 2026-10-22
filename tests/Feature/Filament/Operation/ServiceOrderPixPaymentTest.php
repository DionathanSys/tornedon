<?php

namespace Tests\Feature\Filament\Operation;

use App\Enum\Financial\FinancialAccountType;
use App\Enum\Financial\PixChargeStatus;
use App\Enum\Requisition\Status;
use App\Enum\ServiceOrder\Priority;
use App\Enum\ServiceOrder\State;
use App\Enum\ServiceOrder\Type;
use App\Filament\Management\Resources\CompanyEntitlements\Pages\CreateCompanyEntitlement;
use App\Filament\Management\Resources\CompanyEntitlements\Pages\EditCompanyEntitlement;
use App\Filament\Management\Resources\CompanyEntitlements\Pages\ListCompanyEntitlements;
use App\Filament\Operation\Pages\ServiceOrders\ServiceOrderDetail;
use App\Filament\Operation\Pages\ServiceOrders\ServiceOrderQueue;
use App\Jobs\QueryPixChargeJob;
use App\Jobs\RegisterPixChargeJob;
use App\Livewire\OperationServiceOrderPix;
use App\Models\Bank;
use App\Models\BankAccountConnection;
use App\Models\BillingProvider;
use App\Models\CashMovement;
use App\Models\Company;
use App\Models\CompanyEntitlement;
use App\Models\CompanyPartner;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\Partner;
use App\Models\Product;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\Financial\Banking\BankingEventService;
use App\Services\Financial\Pix\PixProviderRegistry;
use App\Services\Financial\Pix\Providers\IntegraBancosPixClientInterface;
use App\Services\ServiceOrder\ServiceOrderPixPaymentWorkflow;
use App\Support\ServiceOrderPixPayment;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class ServiceOrderPixPaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private ServiceOrder $order;

    private CompanyEntitlement $right;

    private BankAccountConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->user = User::factory()->create();
        $this->company = Company::factory()->create(['created_by' => $this->user->id]);
        $this->user->companies()->attach($this->company, ['is_active' => true, 'role' => 'admin']);
        $this->actingAs($this->user);
        Filament::setCurrentPanel('operation');
        Filament::setTenant($this->company);
        $customer = Partner::create(['name' => 'Cliente PIX', 'document_type' => 'cpf', 'document_number' => '52998224725', 'created_by' => $this->user->id]);
        CompanyPartner::create(['company_id' => $this->company->id, 'partner_id' => $customer->id, 'type' => ['customer'], 'is_active' => true]);
        $this->order = ServiceOrder::create(['number' => 'OS-PIX', 'company_id' => $this->company->id, 'customer_id' => $customer->id, 'created_by' => $this->user->id, 'order_date' => now(), 'status' => State::OPEN, 'priority' => Priority::NORMAL, 'type' => Type::MAINTENANCE]);
        $service = Service::factory()->create(['company_id' => $this->company->id, 'created_by' => $this->user->id, 'price' => 150]);
        $this->order->items()->create(['service_id' => $service->id, 'quantity' => 2, 'unit_price' => 150, 'discount_percentage' => 10, 'discount_amount' => 30, 'created_by' => $this->user->id]);
        $account = FinancialAccount::create(['company_id' => $this->company->id, 'name' => 'Conta PIX', 'type' => FinancialAccountType::BANK, 'opening_balance' => 0, 'is_active' => true, 'created_by' => $this->user->id]);
        $parent = FinancialCategory::create(['company_id' => $this->company->id, 'name' => 'Receitas', 'is_active' => true, 'created_by' => $this->user->id]);
        $category = FinancialCategory::create(['company_id' => $this->company->id, 'parent_id' => $parent->id, 'name' => 'Serviços', 'is_active' => true, 'allow_receivable' => true, 'allow_cash_movement' => true, 'created_by' => $this->user->id]);
        $bank = Bank::firstOrCreate(['code' => '461'], ['name' => 'Banco PIX']);
        $provider = BillingProvider::where('key', 'integrabancos')->firstOrFail();
        $provider->update(['capabilities' => ['pix' => true, 'bank_slip' => true]]);
        $this->connection = BankAccountConnection::create(['company_id' => $this->company->id, 'financial_account_id' => $account->id, 'bank_id' => $bank->id, 'billing_provider_id' => $provider->id, 'credentials' => ['access_token' => 'test-token', 'x_api_key' => 'test-key'], 'settings' => [], 'status' => 'active']);
        CompanyEntitlement::create(['company_id' => $this->company->id, 'feature' => 'pix_charge_issuance', 'enabled' => true]);
        $this->right = CompanyEntitlement::create(['company_id' => $this->company->id, 'feature' => ServiceOrderPixPayment::FEATURE, 'enabled' => true, 'metadata' => ['user_access' => 'selected', 'user_ids' => [$this->user->id], 'financial_account_id' => $account->id, 'financial_category_id' => $category->id]]);
    }

    public function test_one_click_saves_closes_invoices_and_prepares_one_pix_without_fiscal_documents(): void
    {
        Livewire::test(ServiceOrderDetail::class, ['record' => $this->order->id])
            ->set('formData.solution', 'Serviço executado')->call('generatePixPayment')->assertHasNoErrors();
        $this->assertSame(State::INVOICED, $this->order->fresh()->status);
        $this->assertSame('Serviço executado', $this->order->fresh()->solution);
        $invoice = $this->order->fresh()->invoice;
        $this->assertTrue($invoice->confirmed);
        $this->assertSame(270.0, (float) $invoice->netValue);
        $charge = $this->order->latestPixPayment()->sole();
        $this->assertSame(270.0, (float) $charge->amount);
        $this->assertSame($this->user->id, $charge->requested_by);
        $this->assertFalse($charge->installment->accountReceivable->auto_register_receipt_on_due_date);
        $this->assertDatabaseCount('fiscal_documents', 0);
        $this->assertDatabaseCount('account_receivable_installment_payments', 0);
        app(ServiceOrderPixPaymentWorkflow::class)->execute($this->order->fresh(), $this->user);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('account_receivables', 1);
        $this->assertDatabaseCount('pix_charges', 1);
    }

    public function test_integrabancos_payment_is_visible_and_creates_exactly_one_financial_receipt(): void
    {
        $charge = app(ServiceOrderPixPaymentWorkflow::class)->execute($this->order, $this->user);
        $client = Mockery::mock(IntegraBancosPixClientInterface::class);
        $client->shouldReceive('generate')->once()->andReturn(['sucesso' => true, 'encodedImage' => 'image-base64', 'payload' => '000201pix', 'status' => ['codigo' => '2']]);
        $client->shouldReceive('query')->once()->andReturn(['sucesso' => true, 'valor' => '270.00', 'data_pagamento' => now()->toDateTimeString(), 'status' => ['codigo' => '3']]);
        app()->instance(IntegraBancosPixClientInterface::class, $client);
        (new RegisterPixChargeJob($charge->id))->handle(app(PixProviderRegistry::class));
        $component = Livewire::test(OperationServiceOrderPix::class, ['orderId' => $this->order->id])->assertSee('Aguardando pagamento')->assertSee('000201pix');
        (new QueryPixChargeJob($charge->id))->handle(app(PixProviderRegistry::class));
        (new QueryPixChargeJob($charge->id))->handle(app(PixProviderRegistry::class));
        $component->call('refreshPayment')->assertSee('Pagamento confirmado')->assertDontSee('000201pix');
        Livewire::test(OperationServiceOrderPix::class, ['orderId' => $this->order->id])->assertSee('Pagamento confirmado');
        Livewire::test(ServiceOrderQueue::class)->set('activeTab', 'all')->assertSee('PIX: pagamento confirmado');
        $this->assertSame(PixChargeStatus::PAID, $charge->fresh()->status);
        $this->assertDatabaseCount('account_receivable_installment_payments', 1);
        $this->assertDatabaseCount('cash_movements', 1);
    }

    public function test_company_right_and_selected_user_are_required_even_for_direct_calls(): void
    {
        $this->right->update(['enabled' => false]);
        Livewire::test(OperationServiceOrderPix::class, ['orderId' => $this->order->id])->assertDontSee('Gerar cobrança PIX');
        Livewire::test(ServiceOrderDetail::class, ['record' => $this->order->id])->call('generatePixPayment')->assertForbidden();
        $this->right->update(['enabled' => true, 'metadata' => [...$this->right->metadata, 'user_ids' => []]]);
        Livewire::test(ServiceOrderDetail::class, ['record' => $this->order->id])->call('generatePixPayment')->assertForbidden();
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_expired_future_and_missing_base_right_are_denied(): void
    {
        $this->right->update(['ends_at' => now()->subMinute()]);
        $this->assertFalse(ServiceOrderPixPayment::allows($this->user, $this->company->id));
        $this->right->update(['ends_at' => null, 'starts_at' => now()->addDay()]);
        $this->assertFalse(ServiceOrderPixPayment::allows($this->user, $this->company->id));
        $this->right->update(['starts_at' => null]);
        CompanyEntitlement::where('feature', 'pix_charge_issuance')->delete();
        $this->assertFalse(ServiceOrderPixPayment::allows($this->user, $this->company->id));
    }

    public function test_all_users_mode_still_requires_active_membership_and_operation_module_access(): void
    {
        $this->right->update(['metadata' => [...$this->right->metadata, 'user_access' => 'all', 'user_ids' => []]]);
        $this->assertTrue(ServiceOrderPixPayment::allows($this->user, $this->company->id));
        $this->user->companyAccesses()->create(['company_id' => $this->company->id, 'permissions' => [], 'panels' => ['operation' => true], 'navigation' => []]);
        $this->assertFalse(ServiceOrderPixPayment::allows($this->user, $this->company->id));
        $this->user->companyAccesses()->delete();
        $this->user->companies()->updateExistingPivot($this->company->id, ['is_active' => false]);
        $this->assertFalse(ServiceOrderPixPayment::allows($this->user, $this->company->id));
    }

    public function test_cross_company_orders_and_payment_component_are_rejected(): void
    {
        $other = Company::factory()->create();
        $this->order->update(['company_id' => $other->id]);
        Livewire::test(ServiceOrderDetail::class, ['record' => $this->order->id])->call('generatePixPayment')->assertNotFound();
        Livewire::test(OperationServiceOrderPix::class, ['orderId' => $this->order->id])->assertNotFound();
    }

    public function test_invalid_bank_contract_rolls_back_closing_invoice_and_receivables(): void
    {
        $this->order->customer->update(['document_number' => '123']);
        try {
            app(ServiceOrderPixPaymentWorkflow::class)->execute($this->order, $this->user);
            $this->fail('Invalid bank payload should fail.');
        } catch (ValidationException) {
            $this->assertSame(State::OPEN, $this->order->fresh()->status);
            $this->assertNull($this->order->fresh()->invoice_id);
            $this->assertDatabaseCount('invoices', 0);
            $this->assertDatabaseCount('account_receivables', 0);
            $this->assertDatabaseCount('pix_charges', 0);
            Queue::assertNothingPushed();
        }
    }

    public function test_polling_only_refreshes_local_state_without_querying_the_provider(): void
    {
        $charge = app(ServiceOrderPixPaymentWorkflow::class)->execute($this->order, $this->user);
        $charge->update(['status' => PixChargeStatus::REGISTERED]);
        $component = Livewire::test(OperationServiceOrderPix::class, ['orderId' => $this->order->id]);
        $component->call('refreshPayment')->call('refreshPayment');
        Queue::assertNotPushed(QueryPixChargeJob::class);
        $this->right->update(['enabled' => false]);
        $component->call('refreshPayment')->assertDontSee('Compartilhar link');
        Queue::assertNotPushed(QueryPixChargeJob::class);
    }

    public function test_linked_materials_and_travel_are_included_in_the_single_charge(): void
    {
        $this->order->update(['distance_km' => 10, 'value_km' => 3, 'travel_value' => 30]);
        $product = Product::factory()->create(['company_id' => $this->company->id, 'has_stock_control' => false, 'created_by' => $this->user->id]);
        $requisition = Requisition::create(['number' => 'REQ-PIX', 'customer_id' => $this->order->customer_id, 'company_id' => $this->company->id, 'service_order_id' => $this->order->id, 'sale_date' => now(), 'status' => Status::OPEN, 'stock_reserved' => false, 'stock_consumed' => false, 'created_by' => $this->user->id]);
        RequisitionItem::create(['requisition_id' => $requisition->id, 'product_id' => $product->id, 'unit_of_measure' => 'UN', 'quantity' => 1, 'unit_price' => 50, 'discount_amount' => 0, 'stock_consumed' => false, 'created_by' => $this->user->id]);
        $charge = app(ServiceOrderPixPaymentWorkflow::class)->execute($this->order->fresh(), $this->user);
        $this->assertSame(350.0, (float) $charge->amount);
        $this->assertSame($this->order->fresh()->invoice_id, $requisition->fresh()->invoice_id);
        $this->assertSame(Status::INVOICED, $requisition->fresh()->status);
        $this->assertDatabaseCount('account_receivables', 1);
        $this->assertDatabaseCount('fiscal_documents', 0);
    }

    public function test_uncertain_registration_is_resumed_without_a_new_charge_or_resend(): void
    {
        $charge = app(ServiceOrderPixPaymentWorkflow::class)->execute($this->order, $this->user);
        $charge->update(['status' => PixChargeStatus::UNCERTAIN, 'request_sent_at' => now()]);
        Queue::fake();
        $same = app(ServiceOrderPixPaymentWorkflow::class)->execute($this->order->fresh(), $this->user);
        $this->assertSame($charge->id, $same->id);
        Livewire::test(OperationServiceOrderPix::class, ['orderId' => $this->order->id])
            ->call('refreshPayment')->assertSee('Confirmando emissão com o banco');
        (new RegisterPixChargeJob($charge->id))->handle(app(PixProviderRegistry::class));
        Queue::assertNotPushed(RegisterPixChargeJob::class);
        Queue::assertNotPushed(QueryPixChargeJob::class);
        $this->assertDatabaseCount('pix_charges', 1);
    }

    public function test_revoking_the_right_does_not_prevent_settling_an_existing_charge(): void
    {
        $charge = app(ServiceOrderPixPaymentWorkflow::class)->execute($this->order, $this->user);
        $charge->update(['status' => PixChargeStatus::REGISTERED]);
        $this->right->update(['enabled' => false]);
        $client = Mockery::mock(IntegraBancosPixClientInterface::class);
        $client->shouldReceive('query')->once()->andReturn(['sucesso' => true, 'valor' => '270.00', 'data_pagamento' => now()->toDateTimeString(), 'status' => ['codigo' => '3']]);
        app()->instance(IntegraBancosPixClientInterface::class, $client);
        (new QueryPixChargeJob($charge->id))->handle(app(PixProviderRegistry::class));
        $this->assertSame(PixChargeStatus::PAID, $charge->fresh()->status);
        Livewire::test(OperationServiceOrderPix::class, ['orderId' => $this->order->id])->assertSee('Pagamento confirmado')->assertDontSee('Gerar cobrança PIX');
        $this->assertDatabaseCount('cash_movements', 1);
    }

    public function test_failed_financial_posting_does_not_show_success_and_can_be_reprocessed(): void
    {
        $charge = app(ServiceOrderPixPaymentWorkflow::class)->execute($this->order, $this->user);
        $charge->update(['status' => PixChargeStatus::REGISTERED]);
        $event = app(BankingEventService::class)->record($charge, [
            'response' => ['valor' => 270, 'data_hora' => now()->toDateTimeString()],
            'successful' => true, 'charge_status' => '3',
        ], 'ATUALIZACAO', 'webhook');
        $fail = true;
        CashMovement::creating(function () use (&$fail): void {
            if ($fail) {
                throw new \RuntimeException('Simulated posting failure');
            }
        });
        try {
            app(BankingEventService::class)->process($event);
            $this->fail('Posting should fail.');
        } catch (\RuntimeException) {
            $this->assertNotSame(PixChargeStatus::PAID, $charge->fresh()->status);
            $this->assertDatabaseCount('account_receivable_installment_payments', 0);
            Livewire::test(OperationServiceOrderPix::class, ['orderId' => $this->order->id])->assertDontSee('Pagamento confirmado');
        }
        $fail = false;
        app(BankingEventService::class)->process($event->fresh());
        $this->assertSame(PixChargeStatus::PAID, $charge->fresh()->status);
        $this->assertDatabaseCount('cash_movements', 1);
    }

    public function test_management_can_save_the_right_with_user_and_financial_settings(): void
    {
        $this->user->update(['is_admin' => true]);
        Filament::setCurrentPanel('management');
        Filament::setTenant(null);
        Livewire::test(EditCompanyEntitlement::class, ['record' => $this->right->id])
            ->fillForm(['metadata' => [...$this->right->metadata, 'user_access' => 'all']])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame('all', $this->right->fresh()->metadata['user_access']);
        $this->assertSame((string) $this->connection->financial_account_id, (string) $this->right->fresh()->metadata['financial_account_id']);
        $foreign = User::factory()->create();
        Livewire::test(EditCompanyEntitlement::class, ['record' => $this->right->id])
            ->fillForm(['metadata' => [...$this->right->fresh()->metadata, 'user_access' => 'selected', 'user_ids' => [$foreign->id]]])
            ->call('save')->assertHasFormErrors();
        $this->assertSame('all', $this->right->fresh()->metadata['user_access']);
    }

    public function test_management_can_create_the_right_without_losing_selected_account_and_category(): void
    {
        $metadata = $this->right->metadata;
        $this->right->delete();
        $this->user->update(['is_admin' => true]);
        Filament::setCurrentPanel('management');
        Filament::setTenant(null);

        Livewire::test(CreateCompanyEntitlement::class)
            ->set('data.company_id', $this->company->id)
            ->set('data.feature', ServiceOrderPixPayment::FEATURE)
            ->set('data.metadata.user_ids', [$this->user->id])
            ->set('data.metadata.financial_account_id', $metadata['financial_account_id'])
            ->set('data.metadata.financial_category_id', $metadata['financial_category_id'])
            ->set('data.company_id', $this->company->id)
            ->call('create')->assertHasNoFormErrors();

        $right = CompanyEntitlement::where('feature', ServiceOrderPixPayment::FEATURE)->sole();
        $this->assertEquals($metadata['financial_account_id'], $right->metadata['financial_account_id']);
        $this->assertEquals($metadata['financial_category_id'], $right->metadata['financial_category_id']);
    }

    public function test_polling_does_not_dispatch_pending_os_registration(): void
    {
        app(ServiceOrderPixPaymentWorkflow::class)->execute($this->order, $this->user);
        Queue::fake();
        Livewire::test(OperationServiceOrderPix::class, ['orderId' => $this->order->id])
            ->call('refreshPayment')->call('refreshPayment');
        Queue::assertNothingPushed();
    }

    public function test_management_can_create_the_right_from_the_list_modal(): void
    {
        $metadata = $this->right->metadata;
        $this->right->delete();
        $this->user->update(['is_admin' => true]);
        Filament::setCurrentPanel('management');
        Filament::setTenant(null);

        Livewire::test(ListCompanyEntitlements::class)
            ->mountTableAction('create')
            ->set('mountedActions.0.data.company_id', $this->company->id)
            ->set('mountedActions.0.data.feature', ServiceOrderPixPayment::FEATURE)
            ->set('mountedActions.0.data.metadata.user_ids', [$this->user->id])
            ->set('mountedActions.0.data.metadata.financial_account_id', $metadata['financial_account_id'])
            ->set('mountedActions.0.data.metadata.financial_category_id', $metadata['financial_category_id'])
            ->set('mountedActions.0.data.company_id', $this->company->id)
            ->callMountedAction()->assertHasNoFormErrors();

        $right = CompanyEntitlement::where('feature', ServiceOrderPixPayment::FEATURE)->sole();
        $this->assertEquals($metadata['financial_account_id'], $right->metadata['financial_account_id']);
        $this->assertEquals($metadata['financial_category_id'], $right->metadata['financial_category_id']);
    }

    public function test_changing_company_clears_pix_selections_but_preserves_other_metadata(): void
    {
        $this->right->update(['metadata' => [...$this->right->metadata, 'other_setting' => 'preserved']]);
        $this->user->update(['is_admin' => true]);
        $other = Company::factory()->create();
        Filament::setCurrentPanel('management');
        Filament::setTenant(null);

        Livewire::test(EditCompanyEntitlement::class, ['record' => $this->right->id])
            ->set('data.company_id', $other->id)
            ->assertSet('data.metadata.financial_account_id', null)
            ->assertSet('data.metadata.financial_category_id', null)
            ->assertSet('data.metadata.user_ids', [])
            ->assertSet('data.metadata.user_access', 'selected')
            ->assertSet('data.metadata.other_setting', 'preserved')
            ->call('save')
            ->assertHasFormErrors(['metadata.financial_account_id' => 'required', 'metadata.financial_category_id' => 'required']);

        $this->assertSame($this->company->id, $this->right->fresh()->company_id);
    }
}
