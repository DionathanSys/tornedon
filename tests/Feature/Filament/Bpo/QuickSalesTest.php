<?php

namespace Tests\Feature\Filament\Bpo;

use App\Enum\FiscalDocument\NfeStatus;
use App\Enum\FiscalDocument\OperationNature;
use App\Enum\Payment\Condition;
use App\Enum\Payment\Method;
use App\Enum\ServiceOrder\State;
use App\Enum\StockMovement\Type;
use App\Enum\Tax\TaxRegime;
use App\Filament\Bpo\Pages\Sales;
use App\Filament\Bpo\Pages\SaleTemplates;
use App\Models\BpoSale;
use App\Models\Company;
use App\Models\CompanyRoleAssignment;
use App\Models\FiscalProfile;
use App\Models\Partner;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\SaleTemplate;
use App\Models\Service;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Access\CompanyTeamService;
use App\Services\Access\PermissionCatalog;
use App\Services\AccountReceivable\AccountReceivableGenerationService;
use App\Services\AccountReceivable\AccountReceivableService;
use App\Services\Bpo\QuickSaleWorkflow;
use App\Services\FiscalDocument\Actions\ProcessAuthorizedNfeStockMovementsAction;
use App\Services\FiscalDocument\NfseDocumentService;
use App\Services\Invoice\Actions\GenerateInvoiceAccountReceivablesAction;
use App\Services\Invoice\Actions\ReturnInvoiceToPendingAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class QuickSalesTest extends TestCase
{
    use RefreshDatabase;

    private User $bpo;

    private Company $company;

    private Partner $customer;

    private Service $service;

    private SaleTemplate $template;

    private QuickSaleWorkflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        $owner = User::factory()->create();
        $this->bpo = User::factory()->create();
        $this->company = Company::factory()->create(['address' => ['state' => 'SP', 'city' => 'São Paulo']]);
        $owner->companies()->attach($this->company, ['role' => 'owner', 'is_active' => true]);
        FiscalProfile::create(['company_id' => $this->company->id, 'tax_regime' => TaxRegime::LUCRO_PRESUMIDO->value,
            'is_active' => true, 'iss_rate_default' => 5, 'cfop_rules' => [OperationNature::VENDA_DENTRO_ESTADO->value => ['default_cfop' => '5102', 'exceptions' => []]], 'created_by' => $owner->id]);
        $this->customer = Partner::create(['name' => 'Cliente da venda', 'document_type' => 'CNPJ', 'document_number' => '22345678000188', 'created_by' => $owner->id]);
        $this->customer->companies()->attach($this->company, ['is_active' => true, 'type' => '["customer"]']);
        $this->service = Service::factory()->create(['company_id' => $this->company->id, 'created_by' => $owner->id, 'price' => 100]);
        $this->template = SaleTemplate::create(['company_id' => $this->company->id, 'name' => 'Prestação de serviços', 'document_type' => 'nfse', 'stock_control_enabled' => false, 'is_active' => true]);
        $teams = app(CompanyTeamService::class);
        $role = $teams->saveRole($owner, $this->company, ['name' => 'Vendas BPO', 'permissions' => PermissionCatalog::templates()['Administrador da empresa'], 'panels' => ['bpo', 'admin', 'operation']]);
        $teams->authorizeBpo($owner, $this->company, $this->bpo, [$role->id], false, []);
        $this->actingAs($this->bpo);
        Filament::setCurrentPanel(Filament::getPanel('bpo'));
        Filament::setTenant($this->company);
        $this->workflow = app(QuickSaleWorkflow::class);
    }

    private function payload(): array
    {
        return ['sale_template_id' => $this->template->id, 'customer_id' => $this->customer->id, 'sale_date' => today()->toDateString(),
            'items' => [['catalog_id' => $this->service->id, 'quantity' => 2, 'unit_price' => 100, 'discount_amount' => 10]], 'description' => 'Serviço avulso'];
    }

    private function payment(string $policy = 'create_receivable'): array
    {
        return ['financial_policy' => $policy, 'payment_method' => Method::PIX->value, 'payment_condition' => Condition::DAYS_30_60_90->value];
    }

    public function test_service_sale_uses_real_os_and_finalizes_once_without_requesting_emission(): void
    {
        $sale = $this->workflow->save($this->bpo, $this->company, $this->payload(), (string) Str::uuid());
        $this->assertNotNull($sale->service_order_id);
        $this->assertSame(190.0, (float) $sale->source()->total_amount);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('account_receivables', 0);
        $sale = $this->workflow->finalize($this->bpo, $this->company, $sale->id, $sale->revision, $this->payment());
        $again = $this->workflow->finalize($this->bpo, $this->company, $sale->id, 1, $this->payment());
        $this->assertSame($sale->invoice_id, $again->invoice_id);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('account_receivables', 1);
        $this->assertDatabaseCount('account_receivable_installments', 3);
        $this->assertDatabaseCount('cash_movements', 0);
        $document = $sale->invoice->fiscalDocuments()->sole();
        $this->assertNull($document->emission_requested_at);
        Livewire::test(Sales::class)->assertSee('Emissão não solicitada');
        $this->assertSame(1, $document->items()->count());
        $this->assertSame(190.0, (float) $sale->invoice->netValue);
    }

    public function test_fiscal_only_is_respected_by_the_real_callback_service(): void
    {
        $sale = $this->workflow->save($this->bpo, $this->company, $this->payload(), (string) Str::uuid());
        $sale = $this->workflow->finalize($this->bpo, $this->company, $sale->id, $sale->revision, $this->payment('fiscal_only'));
        $doc = $sale->invoice->fiscalDocuments()->sole();
        $doc->update(['nfse_status' => NfeStatus::AUTHORIZED]);
        $this->assertTrue(app(AccountReceivableGenerationService::class)->generateFromFiscalDocument($doc->fresh()));
        $this->assertDatabaseCount('account_receivables', 0);
    }

    public function test_a_product_sale_with_stock_disabled_never_reserves_or_consumes_stock(): void
    {
        $template = SaleTemplate::create(['company_id' => $this->company->id, 'name' => 'Produtos sem estoque', 'document_type' => 'nfe', 'stock_control_enabled' => false, 'is_active' => true]);
        $product = Product::factory()->create(['company_id' => $this->company->id, 'created_by' => $this->bpo->id, 'has_stock_control' => true]);
        $data = $this->payload();
        $data['sale_template_id'] = $template->id;
        $data['items'][0]['catalog_id'] = $product->id;
        $sale = $this->workflow->save($this->bpo, $this->company, $data, (string) Str::uuid());
        $this->assertFalse($sale->source()->stock_control_enabled);
        $sale = $this->workflow->finalize($this->bpo, $this->company, $sale->id, $sale->revision, $this->payment());
        $doc = $sale->invoice->fiscalDocuments()->sole();
        $doc->update(['nfe_status' => NfeStatus::AUTHORIZED]);
        $this->assertFalse($sale->source()->stock_reserved);
        $this->assertTrue(app(ProcessAuthorizedNfeStockMovementsAction::class)->execute($doc));
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_stale_revision_cannot_overwrite_another_edit(): void
    {
        $uuid = (string) Str::uuid();
        $sale = $this->workflow->save($this->bpo, $this->company, $this->payload(), $uuid);
        $this->workflow->save($this->bpo, $this->company, $this->payload(), $uuid, $sale->id, $sale->revision);
        $this->expectException(ValidationException::class);
        $this->workflow->finalize($this->bpo, $this->company, $sale->id, $sale->revision, $this->payment());
    }

    public function test_pages_and_new_sale_modal_render_in_the_bpo_shell(): void
    {
        $this->get(Sales::getUrl(panel: 'bpo', tenant: $this->company))->assertOk()->assertSee('bpo-shell', false);
        $this->get(SaleTemplates::getUrl(panel: 'bpo', tenant: $this->company))->assertOk();
        Livewire::test(Sales::class)->mountAction('newSale')->assertActionMounted('newSale');
    }

    public function test_livewire_creates_a_draft_and_finalizes_it_through_table_action(): void
    {
        Livewire::test(Sales::class)->callAction('newSale', $this->payload())->assertHasNoActionErrors();
        $sale = BpoSale::sole();
        Livewire::test(Sales::class)->callAction(TestAction::make('finalize')->table($sale), ['revision' => $sale->revision] + $this->payment())
            ->assertHasNoActionErrors();
        $this->assertNotNull($sale->fresh()->finalized_at);
        $this->assertDatabaseCount('account_receivables', 1);
    }

    public function test_template_can_be_created_through_the_page_with_server_owned_company(): void
    {
        Livewire::test(SaleTemplates::class)->callAction('newTemplate', ['name' => 'Modelo NF-e', 'document_type' => 'nfe',
            'stock_control_enabled' => false, 'is_active' => true, 'company_id' => 999999])->assertHasNoActionErrors();
        $this->assertSame($this->company->id, SaleTemplate::where('name', 'Modelo NF-e')->sole()->company_id);
    }

    public function test_an_existing_receivable_is_linked_without_recreating_its_installments(): void
    {
        $service = app(AccountReceivableService::class);
        $target = $service->create(['company_id' => $this->company->id, 'customer_id' => $this->customer->id,
            'due_date' => today()->addDays(30), 'due_amount' => 190, 'payment_method' => 'pix', 'installment_count' => 1], $this->bpo->id);
        $this->assertNotNull($target, $service->getMessage());
        $before = $target->installments()->sole()->getRawOriginal();
        $sale = $this->workflow->save($this->bpo, $this->company, $this->payload(), (string) Str::uuid());
        $sale = $this->workflow->finalize($this->bpo, $this->company, $sale->id, $sale->revision, $this->payment('link_existing') + ['financial_target_id' => $target->id]);
        $this->assertSame($sale->invoice_id, $target->fresh()->invoice_id);
        $this->assertSame($before, $target->installments()->sole()->getRawOriginal());
        $this->assertDatabaseCount('account_receivables', 1);
    }

    public function test_failed_financial_link_rolls_back_invoice_documents_and_source_closure(): void
    {
        $sale = $this->workflow->save($this->bpo, $this->company, $this->payload(), (string) Str::uuid());
        try {
            $this->workflow->finalize($this->bpo, $this->company, $sale->id, $sale->revision, $this->payment('link_existing') + ['financial_target_id' => 999999]);
            $this->fail('An invalid target must fail.');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseCount('invoices', 0);
            $this->assertDatabaseCount('fiscal_documents', 0);
            $this->assertNull($sale->fresh()->finalized_at);
            $this->assertNull($sale->source()->invoice_id);
            $this->assertSame(State::OPEN, $sale->source()->status);
        }
    }

    public function test_catalog_from_another_company_is_rejected_and_leaves_no_draft(): void
    {
        Auth::logout();
        $foreign = Service::factory()->create();
        $this->actingAs($this->bpo);
        $data = $this->payload();
        $data['items'][0]['catalog_id'] = $foreign->id;
        try {
            $this->workflow->save($this->bpo, $this->company, $data, (string) Str::uuid());
            $this->fail('A foreign service must not be accepted.');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseCount('bpo_sales', 0);
            $this->assertDatabaseCount('service_orders', 0);
        }
    }

    public function test_revoked_access_blocks_an_already_mounted_sales_page(): void
    {
        $page = Livewire::test(Sales::class);
        DB::table('bpo_engagements')->where('company_id', $this->company->id)->update(['is_active' => false]);
        $page->call('$refresh')->assertForbidden();
    }

    public function test_later_emission_reuses_the_finalized_document_and_does_not_generate_financial_again(): void
    {
        $sale = $this->workflow->save($this->bpo, $this->company, $this->payload(), (string) Str::uuid());
        $sale = $this->workflow->finalize($this->bpo, $this->company, $sale->id, $sale->revision, $this->payment());
        $id = $sale->invoice->fiscalDocuments()->sole()->id;
        $this->mock(NfseDocumentService::class, function ($mock) use ($id): void {
            $mock->shouldReceive('emitir')->once()->withArgs(fn ($doc, $actor) => $doc->id === $id && $actor === $this->bpo->id)->andReturn(true);
            $mock->shouldReceive('hasError')->andReturn(false);
        });
        $this->workflow->requestEmission($this->bpo, $this->company, $sale->id);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('fiscal_documents', 1);
        $this->assertDatabaseCount('account_receivables', 1);
    }

    public function test_enabled_stock_uses_existing_reservation_and_authorization_flow_once(): void
    {
        $template = SaleTemplate::create(['company_id' => $this->company->id, 'name' => 'Produtos com estoque', 'document_type' => 'nfe', 'stock_control_enabled' => true, 'is_active' => true]);
        $product = Product::factory()->create(['company_id' => $this->company->id, 'created_by' => $this->bpo->id, 'has_stock_control' => true]);
        ProductStock::create(['company_id' => $this->company->id, 'product_id' => $product->id, 'quantity_total' => 10, 'quantity_reserved' => 0, 'allow_negative' => false]);
        $data = $this->payload();
        $data['sale_template_id'] = $template->id;
        $data['items'][0]['catalog_id'] = $product->id;
        $sale = $this->workflow->save($this->bpo, $this->company, $data, (string) Str::uuid());
        $this->assertDatabaseCount('stock_movements', 1);
        $sale = $this->workflow->finalize($this->bpo, $this->company, $sale->id, $sale->revision, $this->payment());
        $doc = $sale->invoice->fiscalDocuments()->sole();
        $doc->update(['nfe_status' => NfeStatus::AUTHORIZED]);
        $action = app(ProcessAuthorizedNfeStockMovementsAction::class);
        $result = $action->execute($doc);
        $this->assertTrue($result, (string) $action->getMessage());
        $result = $action->execute($doc->fresh());
        $this->assertTrue($result, (string) $action->getMessage());
        $this->assertSame(1, StockMovement::where('type', Type::EXIT->value)->count());
    }

    public function test_minimal_sales_role_can_finalize_without_administration_permissions(): void
    {
        $role = CompanyRoleAssignment::where('user_id', $this->bpo->id)->sole()->role;
        $role->update(['permissions' => PermissionCatalog::templates()['Operador de vendas BPO']]);
        $sale = $this->workflow->save($this->bpo, $this->company, $this->payload(), (string) Str::uuid());
        $sale = $this->workflow->finalize($this->bpo, $this->company, $sale->id, $sale->revision, $this->payment());
        $this->assertNotNull($sale->invoice_id);
    }

    public function test_webhook_does_not_generate_receivables_for_fiscal_only_sales(): void
    {
        $sale = $this->workflow->save($this->bpo, $this->company, $this->payload(), (string) Str::uuid());
        $sale = $this->workflow->finalize($this->bpo, $this->company, $sale->id, $sale->revision, $this->payment('fiscal_only'));
        $doc = $sale->invoice->fiscalDocuments()->sole();
        $key = str_repeat('7', 44);
        $doc->update(['document_key' => $key]);
        $this->postJson('/webhook/nfe', ['chave' => $key, 'status' => 'autorizado', 'protocolo' => 'protocolo-teste'])->assertOk();
        $this->assertSame(NfeStatus::AUTHORIZED, $doc->fresh()->nfse_status);
        $this->assertDatabaseCount('account_receivables', 0);
    }

    public function test_duplicate_creation_request_and_finalized_edit_are_rejected(): void
    {
        $uuid = (string) Str::uuid();
        $sale = $this->workflow->save($this->bpo, $this->company, $this->payload(), $uuid);
        try {
            $this->workflow->save($this->bpo, $this->company, $this->payload(), $uuid);
            $this->fail('A repeated first submission must not create a second source.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('bpo_sales', 1);
            $this->assertDatabaseCount('service_orders', 1);
        }
        $sale = $this->workflow->finalize($this->bpo, $this->company, $sale->id, $sale->revision, $this->payment());
        $this->expectException(ValidationException::class);
        $this->workflow->save($this->bpo, $this->company, $this->payload(), $uuid, $sale->id, $sale->revision);
    }

    public function test_reopening_or_independent_financial_generation_is_blocked_for_quick_sale(): void
    {
        $sale = $this->workflow->save($this->bpo, $this->company, $this->payload(), (string) Str::uuid());
        $sale = $this->workflow->finalize($this->bpo, $this->company, $sale->id, $sale->revision, $this->payment('fiscal_only'));
        $reopen = new ReturnInvoiceToPendingAction($sale->invoice, $this->bpo->id);
        $this->assertFalse($reopen->execute());
        $generate = new GenerateInvoiceAccountReceivablesAction($sale->invoice, $this->bpo->id);
        $this->assertNull($generate->execute($this->payment()));
        $this->assertDatabaseCount('account_receivables', 0);
    }
}
