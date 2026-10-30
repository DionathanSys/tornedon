<?php

namespace Tests\Feature\Filament\Bpo;

use App\Enum\FiscalDocument\NfeStatus;
use App\Enum\FiscalDocument\OperationNature;
use App\Enum\Invoice\Status;
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
use App\Services\Bpo\SalesReportingService;
use App\Services\FiscalDocument\Actions\ProcessAuthorizedNfeStockMovementsAction;
use App\Services\FiscalDocument\NfseDocumentService;
use App\Services\Invoice\Actions\GenerateInvoiceAccountReceivablesAction;
use App\Services\Invoice\Actions\ReturnInvoiceToPendingAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
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

    private function reportSale(float $price, bool $finalize = true, ?string $date = null, float $travel = 0): BpoSale
    {
        $data = $this->payload();
        $data['sale_date'] = $date ?? today()->toDateString();
        $data['items'][0] = ['catalog_id' => $this->service->id, 'quantity' => 1, 'unit_price' => $price, 'discount_amount' => 0];
        $sale = $this->workflow->save($this->bpo, $this->company, $data, (string) Str::uuid());
        if ($travel) {
            $sale->source()->update(['travel_value' => $travel]);
        }

        return $finalize ? $this->workflow->finalize($this->bpo, $this->company, $sale->id, $sale->revision, $this->payment('fiscal_only')) : $sale;
    }

    public function test_sales_cards_use_net_commercial_totals_and_exclude_cancelled_and_outside_period(): void
    {
        $serviceSale = $this->reportSale(190, travel: 12.34);
        $draft = $this->reportSale(25.50, false);
        $cancelled = $this->reportSale(600);
        $cancelled->invoice->update(['canceled' => true, 'status' => Status::CANCELLED]);
        $outside = $this->reportSale(700, date: today()->startOfMonth()->subDay()->toDateString());
        $template = SaleTemplate::create(['company_id' => $this->company->id, 'name' => 'Produtos para relatório', 'document_type' => 'nfe', 'stock_control_enabled' => false, 'is_active' => true]);
        $product = Product::factory()->create(['company_id' => $this->company->id, 'created_by' => $this->bpo->id]);
        $data = $this->payload();
        $data['sale_template_id'] = $template->id;
        $data['items'][0] = ['catalog_id' => $product->id, 'quantity' => 3, 'unit_price' => 50, 'discount_amount' => 5];
        $productSale = $this->workflow->save($this->bpo, $this->company, $data, (string) Str::uuid());
        $productSale = $this->workflow->finalize($this->bpo, $this->company, $productSale->id, $productSale->revision, $this->payment('fiscal_only'));

        $page = Livewire::test(Sales::class)->assertCanSeeTableRecords([$serviceSale, $productSale, $draft, $cancelled])->assertCanNotSeeTableRecords([$outside]);
        $summary = $page->instance()->salesSummary();
        $this->assertSame(347.34, $summary['billed_amount']);
        $this->assertSame(2, $summary['billed_count']);
        $this->assertSame(173.67, $summary['average_amount']);
        $this->assertSame(1, $summary['draft_count']);
        $this->assertSame(25.50, $summary['draft_amount']);

        $page->filterTable('document_type', 'nfe')->assertCanSeeTableRecords([$productSale])->assertCanNotSeeTableRecords([$serviceSale, $draft]);
        $this->assertSame(145.0, $page->instance()->salesSummary()['billed_amount']);
        $page->filterTable('commercial_status', 'cancelled')->filterTable('document_type', null)->assertCanSeeTableRecords([$cancelled]);
        $this->assertSame(0.0, $page->instance()->salesSummary()['billed_amount']);
    }

    public function test_period_filters_support_sale_and_finalization_dates_and_search_updates_cards(): void
    {
        $first = $this->reportSale(100, date: '2026-02-01');
        $last = $this->reportSale(200, date: '2026-02-28');
        $outside = $this->reportSale(900, date: '2026-03-01');
        $page = Livewire::test(Sales::class)->filterTable('period', ['date_basis' => 'sale_date', 'from' => '2026-02-01', 'until' => '2026-02-28'])
            ->assertCanSeeTableRecords([$first, $last])->assertCanNotSeeTableRecords([$outside]);
        $this->assertSame(300.0, $page->instance()->salesSummary()['billed_amount']);

        $page->searchTable($first->invoice->invoice_number)->assertCanSeeTableRecords([$first]);
        $this->assertSame(100.0, $page->instance()->salesSummary()['billed_amount']);
        $page->searchTable('')->filterTable('period', ['date_basis' => 'finalized_at', 'from' => today()->toDateString(), 'until' => today()->toDateString()])
            ->assertCanSeeTableRecords([$first, $last, $outside]);
        $this->assertSame(1200.0, $page->instance()->salesSummary()['billed_amount']);

        $page->filterTable('period', ['date_basis' => 'sale_date', 'from' => '2026-02-31', 'until' => '2026-03-01'])->assertCountTableRecords(0);
        $this->assertSame(0.0, $page->instance()->salesSummary()['billed_amount']);
    }

    public function test_customer_model_fiscal_and_financial_filters_share_the_same_summary_query(): void
    {
        $first = $this->reportSale(100);
        $second = $this->reportSale(200);
        $first->invoice->fiscalDocuments()->sole()->update(['nfse_status' => NfeStatus::AUTHORIZED]);
        $page = Livewire::test(Sales::class)->filterTable('customer_id', $this->customer->id)->filterTable('sale_template_id', $this->template->id)
            ->filterTable('financial_policy', 'fiscal_only')->filterTable('fiscal_status', NfeStatus::AUTHORIZED->value)
            ->assertCanSeeTableRecords([$first])->assertCanNotSeeTableRecords([$second]);
        $this->assertSame(100.0, $page->instance()->salesSummary()['billed_amount']);
        $page->filterTable('fiscal_status', 'not_requested')->assertCanSeeTableRecords([$second])->assertCanNotSeeTableRecords([$first]);
        $this->assertSame(200.0, $page->instance()->salesSummary()['billed_amount']);
        $page->filterTable('customer_id', 999999)->assertCountTableRecords(0);
        $this->assertSame(0, $page->instance()->salesSummary()['billed_count']);
    }

    public function test_sales_summary_respects_own_scope_and_hides_invoice_metrics_without_permission(): void
    {
        $own = $this->reportSale(100);
        $other = $this->reportSale(900);
        $otherUser = User::factory()->create();
        DB::table('bpo_sales')->where('id', $other->id)->update(['created_by' => $otherUser->id]);
        $role = CompanyRoleAssignment::where('user_id', $this->bpo->id)->sole()->role;
        $permissions = $role->permissions;
        $permissions['bpo_sales.view'] = 'own';
        $role->update(['permissions' => $permissions]);
        $page = Livewire::test(Sales::class)->assertCanSeeTableRecords([$own])->assertCanNotSeeTableRecords([$other]);
        $this->assertSame(100.0, $page->instance()->salesSummary()['billed_amount']);
        Auth::logout();
        $summary = app(SalesReportingService::class)->summary($this->bpo, $this->company, BpoSale::query());
        $this->assertSame(100.0, $summary['billed_amount']);
        $this->actingAs($this->bpo);

        unset($permissions['invoices.view']);
        $role->update(['permissions' => $permissions]);
        $summary = $page->instance()->salesSummary();
        $this->assertNull($summary['billed_amount']);
        $this->assertNull($summary['billed_count']);
        $this->assertNull($summary['average_amount']);
    }

    public function test_sales_cards_include_records_outside_the_current_table_page(): void
    {
        $this->reportSale(100);
        $this->reportSale(200);
        foreach (range(1, 16) as $index) {
            $this->reportSale(10, false);
        }
        $page = Livewire::test(Sales::class);
        $this->assertCount(15, $page->instance()->getTableRecords());
        $summary = $page->instance()->salesSummary();
        $this->assertSame(300.0, $summary['billed_amount']);
        $this->assertSame(2, $summary['billed_count']);
        $this->assertSame(16, $summary['draft_count']);
        $this->assertSame(160.0, $summary['draft_amount']);
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
        $page = Livewire::test(Sales::class)->mountAction('newSale')
            ->set('mountedActions.0.data.sale_template_id', $this->template->id)
            ->set('mountedActions.0.data.customer_id', $this->customer->id);
        $key = array_key_first($page->get('mountedActions.0.data.items'));
        $page->set("mountedActions.0.data.items.{$key}", $this->payload()['items'][0])
            ->callMountedAction()->assertHasNoActionErrors();
        $sale = BpoSale::sole();
        Livewire::test(Sales::class)->callAction(TestAction::make('finalize')->table($sale), ['revision' => $sale->revision] + $this->payment())
            ->assertHasNoActionErrors();
        $this->assertNotNull($sale->fresh()->finalized_at);
        $this->assertDatabaseCount('account_receivables', 1);
    }

    public function test_service_selection_survives_reactive_model_and_item_updates(): void
    {
        $page = Livewire::test(Sales::class)->mountAction('newSale')
            ->set('mountedActions.0.data.sale_template_id', $this->template->id)
            ->set('mountedActions.0.data.customer_id', $this->customer->id);
        $key = array_key_first($page->get('mountedActions.0.data.items'));
        $this->assertTrue(Str::isUuid((string) $key), 'Reactive item replacement must keep UUID keys so browser selects are remounted.');
        $this->assertArrayHasKey('catalog_id', $page->get("mountedActions.0.data.items.{$key}"));
        $this->assertArrayHasKey('unit_price', $page->get("mountedActions.0.data.items.{$key}"));

        $page->set("mountedActions.0.data.items.{$key}.catalog_id", $this->service->id)
            ->assertSet("mountedActions.0.data.items.{$key}.catalog_id", $this->service->id)
            ->assertSet("mountedActions.0.data.items.{$key}.unit_price", 100)
            ->callMountedAction()->assertHasNoActionErrors();

        $sale = BpoSale::sole();
        $this->assertSame($this->service->id, $sale->source()->items()->sole()->service_id);
    }

    public function test_nfe_draft_with_multiple_products_is_saved_and_can_be_edited(): void
    {
        $template = SaleTemplate::create(['company_id' => $this->company->id, 'name' => 'Venda NF-e', 'document_type' => 'nfe', 'stock_control_enabled' => false, 'is_active' => true]);
        $first = Product::factory()->create(['company_id' => $this->company->id, 'created_by' => $this->bpo->id, 'sale_price_value' => 100]);
        $second = Product::factory()->create(['company_id' => $this->company->id, 'created_by' => $this->bpo->id, 'sale_price_value' => 75]);
        $page = Livewire::test(Sales::class)->mountAction('newSale')
            ->set('mountedActions.0.data.sale_template_id', $template->id)
            ->set('mountedActions.0.data.customer_id', $this->customer->id);
        $key = array_key_first($page->get('mountedActions.0.data.items'));
        $page->set("mountedActions.0.data.items.{$key}.catalog_id", $first->id)
            ->callAction(TestAction::make('add')->schemaComponent('items', 'mountedActionSchema0'));
        $keys = array_keys($page->get('mountedActions.0.data.items'));
        $this->assertCount(2, $keys);
        $page->set("mountedActions.0.data.items.{$keys[1]}.catalog_id", $second->id)
            ->set("mountedActions.0.data.items.{$key}.quantity", 1000000)
            ->callMountedAction()->assertHasActionErrors(["items.{$key}.quantity"])
            ->set("mountedActions.0.data.items.{$key}.quantity", 1)
            ->callMountedAction()->assertHasNoActionErrors();
        $sale = BpoSale::sole();
        $this->assertSame(2, $sale->source()->items()->count());
        $this->assertSame(175.0, (float) $sale->source()->total_amount);
        $page->mountAction(TestAction::make('editSale')->table($sale));
        $key = array_key_first($page->get('mountedActions.0.data.items'));
        $page->set("mountedActions.0.data.items.{$key}.quantity", 2)->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame(275.0, (float) $sale->fresh()->source()->total_amount);
    }

    public function test_nfe_business_validation_is_visible_and_preserves_the_unsaved_items(): void
    {
        $template = SaleTemplate::create(['company_id' => $this->company->id, 'name' => 'Venda NF-e', 'document_type' => 'nfe', 'stock_control_enabled' => false, 'is_active' => true]);
        $product = Product::factory()->create(['company_id' => $this->company->id, 'created_by' => $this->bpo->id,
            'name' => 'Produto com mínimo', 'sale_price_value' => 50, 'min_sale_price' => 100]);
        $page = Livewire::test(Sales::class)->mountAction('newSale')
            ->set('mountedActions.0.data.sale_template_id', $template->id)
            ->set('mountedActions.0.data.customer_id', $this->customer->id);
        $key = array_key_first($page->get('mountedActions.0.data.items'));
        $page->set("mountedActions.0.data.items.{$key}.catalog_id", $product->id)
            ->callMountedAction()
            ->assertActionMounted('newSale')
            ->assertHasActionErrors(['items'])
            ->assertNotified('Não foi possível salvar o rascunho')
            ->assertSet("mountedActions.0.data.items.{$key}.catalog_id", $product->id);
        $this->assertDatabaseCount('bpo_sales', 0);
        $this->assertDatabaseCount('requisitions', 0);
        $page->set("mountedActions.0.data.items.{$key}.unit_price", 100)->callMountedAction()->assertHasNoActionErrors();
        $this->assertDatabaseCount('bpo_sales', 1);
    }

    public function test_nfe_missing_stock_is_reported_in_the_modal_without_losing_items(): void
    {
        $template = SaleTemplate::create(['company_id' => $this->company->id, 'name' => 'NF-e com estoque', 'document_type' => 'nfe', 'stock_control_enabled' => true, 'is_active' => true]);
        $product = Product::factory()->create(['company_id' => $this->company->id, 'created_by' => $this->bpo->id, 'has_stock_control' => true, 'sale_price_value' => 100]);
        $page = Livewire::test(Sales::class)->mountAction('newSale')
            ->set('mountedActions.0.data.sale_template_id', $template->id)
            ->set('mountedActions.0.data.customer_id', $this->customer->id);
        $key = array_key_first($page->get('mountedActions.0.data.items'));
        $page->set("mountedActions.0.data.items.{$key}.catalog_id", $product->id)->callMountedAction()
            ->assertHasActionErrors(['items'])->assertActionMounted('newSale')
            ->assertNotified(Notification::make()->title('Não foi possível salvar o rascunho')
                ->body('Cadastre o estoque do produto antes de usar um modelo com controle de estoque.')->danger())
            ->assertSet("mountedActions.0.data.items.{$key}.catalog_id", $product->id);
        $this->assertDatabaseCount('requisitions', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_nfe_item_validation_details_are_not_replaced_with_a_generic_message(): void
    {
        $template = SaleTemplate::create(['company_id' => $this->company->id, 'name' => 'NF-e', 'document_type' => 'nfe', 'stock_control_enabled' => false, 'is_active' => true]);
        $product = Product::factory()->create(['company_id' => $this->company->id, 'created_by' => $this->bpo->id,
            'name' => 'Produto com mínimo', 'sale_price_value' => 50, 'min_sale_price' => 100]);
        try {
            $data = $this->payload();
            $data['sale_template_id'] = $template->id;
            $data['items'][0] = ['catalog_id' => $product->id, 'quantity' => 1, 'unit_price' => 50, 'discount_amount' => 0];
            $this->workflow->save($this->bpo, $this->company, $data, (string) Str::uuid());
            $this->fail('A venda abaixo do preço mínimo deve ser recusada.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('abaixo do preço mínimo', $exception->errors()['items'][0]);
            $this->assertStringContainsString('Produto com mínimo', $exception->errors()['items'][0]);
        }
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
