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
use App\Filament\Bpo\Pages\SaleEditor;
use App\Filament\Bpo\Pages\Sales;
use App\Filament\Bpo\Pages\SaleTemplates;
use App\Filament\Bpo\Resources\InvoiceResource;
use App\Filament\Bpo\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Bpo\Resources\ProductResource;
use App\Filament\Bpo\Resources\Products\Pages\CreateProduct;
use App\Filament\Bpo\Resources\ServiceResource;
use App\Filament\Bpo\Resources\Services\Pages\CreateService;
use App\Jobs\ScheduleBankSlipIssuanceJob;
use App\Jobs\SchedulePixChargeIssuanceJob;
use App\Jobs\SendInvoiceEmailJob;
use App\Models\BpoSale;
use App\Models\CardInstitution;
use App\Models\CashMovement;
use App\Models\Company;
use App\Models\CompanyRoleAssignment;
use App\Models\FinancialAccount;
use App\Models\FiscalProfile;
use App\Models\Invoice;
use App\Models\Partner;
use App\Models\Product;
use App\Models\ProductionOrder;
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
use App\Services\Invoice\InvoiceService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
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

    private function fillSalePage(Testable $page, array $data): Testable
    {
        foreach ($data as $field => $value) {
            $page->set('data.'.$field, $value);
        }

        return $page;
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
        $page = Livewire::test(Sales::class)->filterTable('sale_date', ['date_range' => '01/02/2026 - 28/02/2026'])
            ->assertCanSeeTableRecords([$first, $last])->assertCanNotSeeTableRecords([$outside]);
        $this->assertSame(300.0, $page->instance()->salesSummary()['billed_amount']);

        $page->searchTable($first->invoice->invoice_number)->assertCanSeeTableRecords([$first]);
        $this->assertSame(100.0, $page->instance()->salesSummary()['billed_amount']);
        $page->searchTable('')->filterTable('finalized_at', ['date_range' => today()->format('d/m/Y').' - '.today()->format('d/m/Y')])
            ->assertCanSeeTableRecords([$first, $last])->assertCanNotSeeTableRecords([$outside]);
        $this->assertSame(300.0, $page->instance()->salesSummary()['billed_amount']);

        $page->filterTable('sale_date', ['date_range' => null])
            ->assertCanSeeTableRecords([$first, $last, $outside]);
        $this->assertSame(1200.0, $page->instance()->salesSummary()['billed_amount']);

        $page->filterTable('sale_date', ['date_range' => '31/02/2026 - 01/03/2026'])->assertCountTableRecords(0);
        $this->assertSame(0.0, $page->instance()->salesSummary()['billed_amount']);

        $page->filterTable('sale_date', ['date_range' => '28/02/2026 - 01/02/2026'])->assertCountTableRecords(0);
        $page->filterTable('sale_date', ['date_range' => null])
            ->assertCanSeeTableRecords([$first, $last, $outside]);

        $page->filterTable('finalized_at', ['date_range' => '31/02/2026 - 01/03/2026'])->assertCountTableRecords(0);
        $page->filterTable('finalized_at', ['date_range' => null])->assertCanSeeTableRecords([$first, $last, $outside]);
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

    public function test_sales_listing_keeps_queries_bounded_and_aggregates_summary_in_two_queries(): void
    {
        $sale = $this->reportSale(100, travel: 12.34);
        foreach (range(1, 15) as $index) {
            $this->reportSale(10, false);
        }

        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $page = Livewire::test(Sales::class);
            $queries = DB::getQueryLog();
            DB::flushQueryLog();
            $page->set('tableRecordsPerPage', 5);
            $smallPageQueries = count(DB::getQueryLog());
            DB::flushQueryLog();
            $summary = $page->instance()->salesSummary();
            $aggregates = collect(DB::getQueryLog())->filter(fn (array $query) => str_contains(strtolower($query['query']), ' aggregate'));
        } finally {
            DB::disableQueryLog();
        }

        $this->assertSame(112.34, $summary['billed_amount']);
        $this->assertSame(150.0, $summary['draft_amount']);
        $page->assertTableColumnStateSet('amount', 112.34, $sale);
        $this->assertLessThan(250, count($queries), json_encode(['total' => count($queries), 'repeated' => collect($queries)->countBy('query')->sortDesc()->take(8)->all()], JSON_PRETTY_PRINT));
        $this->assertLessThanOrEqual(20, count($queries) - $smallPageQueries);
        $this->assertCount(2, $aggregates);
    }

    public function test_sales_summary_applies_source_and_invoice_scopes_without_a_session_actor(): void
    {
        $visible = $this->reportSale(100, travel: 12.34);
        $hiddenSource = $this->reportSale(900);
        $hiddenInvoice = $this->reportSale(500);
        $other = User::factory()->create();
        DB::table('service_orders')->where('id', $hiddenSource->service_order_id)->update(['created_by' => $other->id]);
        DB::table('invoices')->where('id', $hiddenInvoice->invoice_id)->update(['created_by' => $other->id]);
        $role = CompanyRoleAssignment::where('user_id', $this->bpo->id)->sole()->role;
        $permissions = $role->permissions;
        $permissions['service_orders.view'] = 'own';
        $permissions['invoices.view'] = 'own';
        $role->update(['permissions' => $permissions]);
        Auth::logout();

        $reporting = app(SalesReportingService::class);
        $summary = $reporting->summary($this->bpo, $this->company, BpoSale::query());
        $this->assertSame(112.34, $summary['billed_amount']);
        $this->assertSame(1, $summary['billed_count']);
        $this->assertEqualsCanonicalizing([$visible->id, $hiddenInvoice->id], $reporting->query($this->bpo, $this->company)->pluck('id')->all());

        unset($permissions['service_orders.view']);
        $role->update(['permissions' => $permissions]);
        $summary = $reporting->summary($this->bpo, $this->company, BpoSale::query());
        $this->assertSame(0.0, $summary['billed_amount']);
        $this->assertSame(0, $summary['billed_count']);
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

    public function test_sales_listing_links_to_the_sale_page_in_the_bpo_shell(): void
    {
        $this->get(Sales::getUrl(panel: 'bpo', tenant: $this->company))->assertOk()->assertSee('bpo-shell', false);
        $this->get(SaleTemplates::getUrl(panel: 'bpo', tenant: $this->company))->assertOk();
        Livewire::test(Sales::class)->assertActionHasUrl('newSale', SaleEditor::getUrl());
        $this->get(SaleEditor::getUrl())->assertOk()->assertSee('Conteúdo da nota fiscal');
    }

    public function test_product_and_service_catalogs_are_available_in_the_bpo_shell(): void
    {
        $product = Product::factory()->create(['company_id' => $this->company->id, 'created_by' => $this->bpo->id]);
        foreach ([ProductResource::class => $product, ServiceResource::class => $this->service] as $resource => $record) {
            foreach (['index' => [], 'create' => [], 'edit' => ['record' => $record]] as $page => $parameters) {
                $this->get($resource::getUrl($page, $parameters, panel: 'bpo', tenant: $this->company))->assertOk()->assertSee('bpo-shell', false);
            }
        }
    }

    public function test_bpo_catalog_creates_products_and_services_for_the_current_company(): void
    {
        Livewire::test(CreateService::class)->fillForm(['name' => 'Serviço cadastrado pelo BPO', 'price' => '125,50'])
            ->set('data.company_id', 999999)->call('create')->assertHasNoFormErrors();
        $service = Service::where('name', 'Serviço cadastrado pelo BPO')->sole();
        $this->assertSame($this->company->id, $service->company_id);
        $this->assertSame(125.50, (float) $service->price);

        Livewire::test(CreateProduct::class)->fillForm(['name' => 'Produto cadastrado pelo BPO'])
            ->set('data.company_id', 999999)->call('create')->assertHasNoFormErrors();
        $this->assertSame($this->company->id, Product::where('name', 'Produto cadastrado pelo BPO')->sole()->company_id);
    }

    public function test_catalog_shortcut_tracks_sale_type_and_create_permission(): void
    {
        $page = Livewire::test(SaleEditor::class)->set('data.sale_template_id', $this->template->id);
        $page->assertActionHasUrl('catalog', ServiceResource::getUrl('create'))->assertActionShouldOpenUrlInNewTab('catalog');

        $template = SaleTemplate::create(['company_id' => $this->company->id, 'name' => 'Venda de produtos', 'document_type' => 'nfe', 'stock_control_enabled' => false, 'is_active' => true]);
        $page->set('data.sale_template_id', $template->id)
            ->assertActionVisible('catalog')->assertActionHasUrl('catalog', ProductResource::getUrl('create'));

        $role = CompanyRoleAssignment::where('user_id', $this->bpo->id)->sole()->role;
        $permissions = $role->permissions;
        unset($permissions['products.create'], $permissions['services.create']);
        $role->update(['permissions' => $permissions]);
        $page->call('$refresh')->assertActionHidden('catalog');
        $this->get(ProductResource::getUrl('create'))->assertForbidden();
        $this->get(ServiceResource::getUrl('create'))->assertForbidden();
    }

    public function test_bpo_catalog_cannot_edit_records_from_another_company(): void
    {
        Auth::logout();
        $product = Product::factory()->create();
        $service = Service::factory()->create();
        $this->actingAs($this->bpo);

        $this->get(ProductResource::getUrl('edit', ['record' => $product]))->assertNotFound();
        $this->get(ServiceResource::getUrl('edit', ['record' => $service]))->assertNotFound();
    }

    public function test_livewire_saves_a_draft_and_table_action_links_to_its_review_page(): void
    {
        $page = Livewire::test(SaleEditor::class);
        $this->fillSalePage($page, $this->payload())->call('saveDraft')->assertHasNoFormErrors();
        $sale = BpoSale::sole();
        Livewire::test(Sales::class)->assertActionHasUrl(TestAction::make('finalize')->table($sale), SaleEditor::getUrl(['sale' => $sale->id]));
        $page = Livewire::test(SaleEditor::class, ['sale' => $sale->id]);
        $this->fillSalePage($page, $this->payment())->call('finalizeSale')->assertHasNoFormErrors();
        $this->assertNotNull($sale->fresh()->finalized_at);
        $this->assertDatabaseCount('account_receivables', 1);
    }

    public function test_service_selection_survives_reactive_model_and_item_updates(): void
    {
        $page = Livewire::test(SaleEditor::class)->set('data.sale_template_id', $this->template->id)
            ->set('data.customer_id', $this->customer->id)->mountAction('addItem')
            ->set('mountedActions.0.data.catalog_id', $this->service->id)
            ->assertSet('mountedActions.0.data.unit_price', '100,00')
            ->callMountedAction()->assertHasNoActionErrors();
        $key = array_key_first($page->get('data.items'));
        $this->assertTrue(Str::isUuid((string) $key));
        $page->assertSet("data.items.{$key}.catalog_id", $this->service->id)
            ->call('saveDraft')->assertHasNoFormErrors();

        $sale = BpoSale::sole();
        $this->assertSame($this->service->id, $sale->source()->items()->sole()->service_id);
    }

    public function test_new_sale_can_be_finalized_on_the_page(): void
    {
        $page = Livewire::test(SaleEditor::class);
        $this->fillSalePage($page, $this->payload() + $this->payment())
            ->call('finalizeSale')->assertHasNoFormErrors()->assertNotified('Venda finalizada');

        $sale = BpoSale::sole();
        $this->assertNotNull($sale->finalized_at);
        $this->assertSame(190.0, (float) $sale->invoice->netValue);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('account_receivables', 1);
        $this->assertDatabaseCount('account_receivable_installments', 3);
    }

    public function test_sale_page_preserves_fiscal_content_in_draft_and_generates_the_nfse_with_it(): void
    {
        $page = Livewire::test(SaleEditor::class);
        $this->fillSalePage($page, $this->payload() + [
            'fiscal_description' => 'Consultoria referente a novembro. Contrato 123.',
            'fiscal_additional_information' => 'Pedido do cliente: 456.',
        ])->call('saveDraft')->assertHasNoFormErrors();
        $sale = BpoSale::sole();
        $page = Livewire::test(SaleEditor::class, ['sale' => $sale->id])
            ->assertSet('data.fiscal_description', 'Consultoria referente a novembro. Contrato 123.')
            ->assertSet('data.fiscal_additional_information', 'Pedido do cliente: 456.');
        $this->fillSalePage($page, $this->payment('fiscal_only'))->call('finalizeSale')->assertHasNoFormErrors();
        $document = $sale->fresh()->invoice->fiscalDocuments()->sole();
        $this->assertSame('Consultoria referente a novembro. Contrato 123.', $document->items()->sole()->description);
        $this->assertSame('Pedido do cliente: 456.', $document->items()->sole()->additional_information);
        $this->assertNull($document->emission_requested_at);
    }

    public function test_nfe_additional_and_purchase_information_survive_draft_and_reach_the_document(): void
    {
        $template = SaleTemplate::create(['company_id' => $this->company->id, 'name' => 'Produtos com dados de compra', 'document_type' => 'nfe', 'stock_control_enabled' => false, 'is_active' => true]);
        $product = Product::factory()->create(['company_id' => $this->company->id, 'created_by' => $this->bpo->id]);
        $fields = ['fiscal_additional_information' => 'Entrega conforme contrato.', 'additional_tax_information' => 'Informação fiscal da operação.',
            'additional_purchase_information_nota_empenho' => 'NE-2026/123', 'additional_purchase_information_pedido' => 'PED-456',
            'additional_purchase_information_contrato' => 'Contrato nº 789'];
        $data = $this->payload();
        $data['sale_template_id'] = $template->id;
        $data['items'][0]['catalog_id'] = $product->id;
        $page = Livewire::test(SaleEditor::class);
        $this->fillSalePage($page, $data + $fields)->call('saveDraft')->assertHasNoFormErrors();
        $sale = BpoSale::sole();
        $page = Livewire::test(SaleEditor::class, ['sale' => $sale->id])
            ->assertSee('Informações adicionais de compra')->assertSee('Informações ao Fisco')->assertSee('Nota de Empenho');
        foreach ($fields as $field => $value) {
            $page->assertSet('data.'.$field, $value);
        }
        $this->fillSalePage($page, $this->payment('fiscal_only'))->call('finalizeSale')->assertHasNoFormErrors();
        $document = $sale->fresh()->invoice->fiscalDocuments()->sole();
        $this->assertSame($fields['fiscal_additional_information'], $document->additional_taxpayer_information);
        $this->assertSame($fields['additional_tax_information'], $document->additional_tax_information);
        $this->assertSame(['nota_empenho' => 'NE-2026/123', 'pedido' => 'PED-456', 'contrato' => 'Contrato nº 789'], json_decode($document->additional_purchase_information, true));
    }

    public function test_sale_page_bills_an_existing_closed_os_without_recreating_its_items(): void
    {
        $draft = $this->workflow->save($this->bpo, $this->company, $this->payload(), (string) Str::uuid());
        $source = $draft->source();
        DB::table('bpo_sales')->where('id', $draft->id)->delete();
        $source->update(['status' => State::CLOSED]);
        $itemId = $source->items()->sole()->id;
        $page = Livewire::test(SaleEditor::class)->set('data.source_type', 'service_order')->set('data.source_id', $source->id)
            ->set('data.sale_template_id', $this->template->id)->assertSet('data.customer_id', $this->customer->id)
            ->assertSee($this->service->name);
        $this->fillSalePage($page, $this->payment('fiscal_only') + ['fiscal_description' => 'Atendimento concluído, OS '.$source->number])
            ->call('finalizeSale')->assertHasNoFormErrors();
        $sale = BpoSale::sole();
        $this->assertSame($source->id, $sale->service_order_id);
        $this->assertSame($itemId, $source->items()->sole()->id);
        $this->assertSame(190.0, (float) $sale->invoice->netValue);
        $this->assertSame(State::INVOICED, $source->fresh()->status);
    }

    public function test_sale_page_bills_existing_requisitions_and_completed_production_orders(): void
    {
        $template = SaleTemplate::create(['company_id' => $this->company->id, 'name' => 'Produtos', 'document_type' => 'nfe', 'stock_control_enabled' => false, 'is_active' => true]);
        $product = Product::factory()->create(['company_id' => $this->company->id, 'created_by' => $this->bpo->id]);
        foreach (['requisition', 'production_order'] as $type) {
            $payload = $this->payload();
            $payload['sale_template_id'] = $template->id;
            $payload['items'][0]['catalog_id'] = $product->id;
            $draft = $this->workflow->save($this->bpo, $this->company, $payload, (string) Str::uuid());
            $source = $draft->source();
            DB::table('bpo_sales')->where('id', $draft->id)->delete();
            $selected = $source;
            if ($type === 'production_order') {
                $selected = ProductionOrder::create(['company_id' => $this->company->id, 'customer_id' => $this->customer->id,
                    'status' => 'completed', 'priority' => 'normal', 'destination_type' => 'direct_delivery', 'created_by' => $this->bpo->id]);
                $selected->items()->create(['product_id' => $product->id, 'quantity' => 2, 'quantity_produced' => 2, 'quantity_approved' => 2, 'unit_price' => 100]);
                $source->update(['production_order_id' => $selected->id]);
            }
            $itemId = $source->items()->sole()->id;
            $page = Livewire::test(SaleEditor::class)->set('data.source_type', $type)->set('data.source_id', $selected->id)
                ->set('data.sale_template_id', $template->id);
            $this->fillSalePage($page, $this->payment('fiscal_only') + ['fiscal_additional_information' => 'Contrato 789.',
                'additional_tax_information' => 'Dados fiscais da origem.', 'additional_purchase_information_pedido' => 'Pedido 123'])
                ->call('finalizeSale')->assertHasNoFormErrors();
            $sale = BpoSale::where('requisition_id', $source->id)->sole();
            $this->assertSame($itemId, $source->items()->sole()->id);
            $this->assertSame('Contrato 789.', $sale->invoice->fiscalDocuments()->sole()->additional_taxpayer_information);
            $this->assertSame('Dados fiscais da origem.', $sale->invoice->fiscalDocuments()->sole()->additional_tax_information);
            $this->assertSame(['pedido' => 'Pedido 123'], json_decode($sale->invoice->fiscalDocuments()->sole()->additional_purchase_information, true));
            if ($type === 'production_order') {
                $this->assertSame($sale->invoice_id, $selected->fresh()->invoice_id);
                $this->assertSame('invoiced', $selected->fresh()->status->value);
            }
        }
    }

    public function test_existing_source_cannot_be_bound_twice_or_rewritten_as_a_new_sale(): void
    {
        $draft = $this->workflow->save($this->bpo, $this->company, $this->payload(), (string) Str::uuid());
        $source = $draft->source();
        DB::table('bpo_sales')->where('id', $draft->id)->delete();
        $payload = ['source_type' => 'service_order', 'source_id' => $source->id, 'sale_template_id' => $this->template->id];
        $sale = $this->workflow->saveExisting($this->bpo, $this->company, $payload, (string) Str::uuid());
        $itemId = $source->items()->sole()->id;
        foreach (['duplicate', 'rewrite'] as $attempt) {
            try {
                if ($attempt === 'duplicate') {
                    $this->workflow->saveExisting($this->bpo, $this->company, $payload, (string) Str::uuid());
                } else {
                    $this->workflow->save($this->bpo, $this->company, $this->payload(), $sale->request_id, $sale->id, $sale->revision);
                }
                $this->fail('A origem deve permanecer vinculada a uma única venda.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
        $this->assertSame($itemId, $source->items()->sole()->id);
        $this->assertDatabaseCount('bpo_sales', 1);
    }

    public function test_existing_os_includes_linked_products_and_generates_both_fiscal_documents(): void
    {
        $serviceDraft = $this->workflow->save($this->bpo, $this->company, $this->payload(), (string) Str::uuid());
        $order = $serviceDraft->source();
        DB::table('bpo_sales')->where('id', $serviceDraft->id)->delete();
        $template = SaleTemplate::create(['company_id' => $this->company->id, 'name' => 'Produtos vinculados', 'document_type' => 'nfe', 'stock_control_enabled' => false, 'is_active' => true]);
        $product = Product::factory()->create(['company_id' => $this->company->id, 'created_by' => $this->bpo->id]);
        $payload = $this->payload();
        $payload['sale_template_id'] = $template->id;
        $payload['items'][0]['catalog_id'] = $product->id;
        $productDraft = $this->workflow->save($this->bpo, $this->company, $payload, (string) Str::uuid());
        $requisition = $productDraft->source();
        DB::table('bpo_sales')->where('id', $productDraft->id)->delete();
        $requisition->update(['service_order_id' => $order->id]);
        $page = Livewire::test(SaleEditor::class)->set('data.source_type', 'service_order')->set('data.source_id', $order->id)
            ->set('data.sale_template_id', $this->template->id)->assertSee($product->name)->assertSee($this->service->name);
        $page->assertSee('Informações adicionais de compra')->assertSee('Informações ao Fisco');
        $this->fillSalePage($page, $this->payment() + ['fiscal_description' => 'Serviço completo.', 'fiscal_additional_information' => 'Contrato comum.',
            'additional_tax_information' => 'Dados da NF-e vinculada.', 'additional_purchase_information_contrato' => 'Contrato comum 123'])
            ->call('finalizeSale')->assertHasNoFormErrors();
        $sale = BpoSale::sole();
        $this->assertSame($requisition->id, $sale->requisition_id);
        $this->assertSame(380.0, (float) $sale->invoice->netValue);
        $this->assertSame(2, $sale->invoice->fiscalDocuments()->count());
        $this->assertSame($sale->invoice_id, $requisition->fresh()->invoice_id);
        $this->assertSame(380.0, (float) $sale->invoice->accountReceivables()->get()->sum('due_amount'));
        $this->assertSame('Contrato comum.', $sale->invoice->fiscalDocuments()->where('document_type', 'nfe')->sole()->additional_taxpayer_information);
        $this->assertSame('Dados da NF-e vinculada.', $sale->invoice->fiscalDocuments()->where('document_type', 'nfe')->sole()->additional_tax_information);
        $this->assertSame(['contrato' => 'Contrato comum 123'], json_decode($sale->invoice->fiscalDocuments()->where('document_type', 'nfe')->sole()->additional_purchase_information, true));
        $this->assertSame('Serviço completo.', $sale->invoice->fiscalDocuments()->where('document_type', 'nfse')->sole()->items()->sole()->description);
        Livewire::test(Sales::class)->assertCanSeeTableRecords([$sale])->assertSee('380,00');
        $summary = Livewire::test(Sales::class)->mountAction(TestAction::make('details')->table($sale));
        $html = $summary->instance()->getMountedAction()->getModalContent()->render();
        $this->assertStringContainsString($product->name, $html);
        $this->assertStringContainsString('380,00', $html);
    }

    public function test_sale_page_cannot_open_a_draft_from_another_company_or_finalize_without_approval(): void
    {
        $sale = $this->workflow->save($this->bpo, $this->company, $this->payload(), (string) Str::uuid());
        $other = Company::factory()->create();
        DB::table('bpo_sales')->where('id', $sale->id)->update(['company_id' => $other->id]);
        $this->get(SaleEditor::getUrl(['sale' => $sale->id]))->assertNotFound();
        $this->get(Sales::getUrl())->assertOk();

        $role = CompanyRoleAssignment::where('user_id', $this->bpo->id)->sole()->role;
        $permissions = $role->permissions;
        unset($permissions['bpo_sales.approve']);
        $role->update(['permissions' => $permissions]);
        Livewire::test(SaleEditor::class)->call('finalizeSale')->assertForbidden();
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_operator_without_approval_can_still_save_a_draft_from_items(): void
    {
        $role = CompanyRoleAssignment::where('user_id', $this->bpo->id)->sole()->role;
        $permissions = $role->permissions;
        unset($permissions['bpo_sales.approve']);
        $role->update(['permissions' => $permissions]);

        $page = Livewire::test(SaleEditor::class)->assertDontSee('Finalizar venda');
        $this->fillSalePage($page, $this->payload())
            ->call('saveDraft')->assertHasNoFormErrors()->assertNotified('Rascunho salvo');

        $this->assertNull(BpoSale::sole()->finalized_at);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_sale_money_fields_accept_brazilian_currency_and_preserve_values_when_reopened(): void
    {
        $data = $this->payload();
        $data['items'][0]['unit_price'] = '1.234,56';
        $data['items'][0]['discount_amount'] = '10,50';
        $page = Livewire::test(SaleEditor::class)->set('data.sale_template_id', $this->template->id)
            ->set('data.customer_id', $this->customer->id);
        $page->callAction('addItem', $data['items'][0])->assertHasNoActionErrors()->call('saveDraft')->assertHasNoFormErrors();

        $sale = BpoSale::sole();
        $this->assertSame(2458.62, (float) $sale->source()->total_amount);
        $page = Livewire::test(SaleEditor::class, ['sale' => $sale->id]);
        $key = array_key_first($page->get('data.items'));
        $page->mountAction('editItem', ['key' => $key])->assertSet('mountedActions.0.data.unit_price', '1.234,56')
            ->assertSet('mountedActions.0.data.discount_amount', '10,50')->callMountedAction()->assertHasNoActionErrors()
            ->call('saveDraft')->assertHasNoFormErrors();
        $this->assertSame(2458.62, (float) $sale->fresh()->source()->total_amount);
    }

    public function test_existing_draft_can_be_edited_and_finalized_on_the_page(): void
    {
        $sale = $this->workflow->save($this->bpo, $this->company, $this->payload(), (string) Str::uuid());
        $data = $this->payload() + $this->payment('fiscal_only');
        $data['items'][0]['quantity'] = 3;

        $page = Livewire::test(SaleEditor::class, ['sale' => $sale->id]);
        $this->fillSalePage($page, $data)->call('finalizeSale')->assertHasNoFormErrors()->assertNotified('Venda finalizada');

        $this->assertNotNull($sale->fresh()->finalized_at);
        $this->assertSame(290.0, (float) $sale->fresh()->invoice->netValue);
        $this->assertDatabaseCount('bpo_sales', 1);
        $this->assertDatabaseCount('account_receivables', 0);
    }

    public function test_inline_finalization_failure_preserves_form_and_rolls_back_the_new_sale(): void
    {
        $target = app(AccountReceivableService::class)->create(['company_id' => $this->company->id, 'customer_id' => $this->customer->id,
            'description' => 'Recebível com valor incompatível', 'due_date' => today()->addDays(30), 'due_amount' => 10, 'payment_method' => 'pix', 'installment_count' => 1], $this->bpo->id);
        $page = Livewire::test(SaleEditor::class);
        $this->fillSalePage($page, $this->payload() + $this->payment('link_existing') + [
            'financial_target_id' => $target->id]);
        $page->call('finalizeSale')->assertHasFormErrors(['items'])
            ->assertNotified('Não foi possível finalizar a venda');
        $this->assertDatabaseCount('bpo_sales', 0);
        $this->assertDatabaseCount('service_orders', 0);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('account_receivables', 1);

        $page->set('data.financial_policy', 'create_receivable')->call('finalizeSale')->assertHasNoFormErrors();
        $this->assertNotNull(BpoSale::sole()->finalized_at);
    }

    public function test_page_finalization_can_request_emission(): void
    {
        $this->mock(NfseDocumentService::class, function ($mock): void {
            $mock->shouldReceive('emitir')->once()->andReturn(true);
            $mock->shouldReceive('hasError')->andReturn(false);
        });

        $page = Livewire::test(SaleEditor::class);
        $this->fillSalePage($page, $this->payload() + $this->payment() + ['request_emission' => true])
            ->call('finalizeSale')->assertHasNoFormErrors()->assertNotified('Emissão solicitada');

        $this->assertNotNull(BpoSale::sole()->finalized_at);
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_emission_failure_preserves_finalized_sale_and_returns_to_listing(): void
    {
        $this->mock(NfseDocumentService::class, function ($mock): void {
            $mock->shouldReceive('emitir')->once()->andThrow(ValidationException::withMessages(['emission' => 'Emissão indisponível.']));
        });

        $page = Livewire::test(SaleEditor::class);
        $this->fillSalePage($page, $this->payload() + $this->payment() + ['request_emission' => true])
            ->call('finalizeSale')->assertHasNoFormErrors()
            ->assertNotified('Venda finalizada, mas a emissão não foi solicitada');

        $this->assertNotNull(BpoSale::sole()->finalized_at);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('account_receivables', 1);
    }

    public function test_nfe_draft_with_multiple_products_is_saved_and_can_be_edited(): void
    {
        $template = SaleTemplate::create(['company_id' => $this->company->id, 'name' => 'Venda NF-e', 'document_type' => 'nfe', 'stock_control_enabled' => false, 'is_active' => true]);
        $first = Product::factory()->create(['company_id' => $this->company->id, 'created_by' => $this->bpo->id, 'sale_price_value' => 100]);
        $second = Product::factory()->create(['company_id' => $this->company->id, 'created_by' => $this->bpo->id, 'sale_price_value' => 75]);
        $page = Livewire::test(SaleEditor::class)->set('data.sale_template_id', $template->id)
            ->set('data.customer_id', $this->customer->id)
            ->callAction('addItem', ['catalog_id' => $first->id, 'quantity' => 1, 'unit_price' => '100,00', 'discount_amount' => 0])
            ->callAction('addItem', ['catalog_id' => $second->id, 'quantity' => 1, 'unit_price' => '75,00', 'discount_amount' => 0])->assertHasNoActionErrors();
        $keys = array_keys($page->get('data.items'));
        $key = $keys[0];
        $this->assertCount(2, $keys);
        $page->set("data.items.{$key}.quantity", 1000000)->call('saveDraft')->assertHasFormErrors(['items.0.quantity'])
            ->set("data.items.{$key}.quantity", 1)->call('saveDraft')->assertHasNoFormErrors();
        $sale = BpoSale::sole();
        $this->assertSame(2, $sale->source()->items()->count());
        $this->assertSame(175.0, (float) $sale->source()->total_amount);
        $page = Livewire::test(SaleEditor::class, ['sale' => $sale->id]);
        $key = array_key_first($page->get('data.items'));
        $page->set("data.items.{$key}.quantity", 2)->call('saveDraft')->assertHasNoFormErrors();
        $this->assertSame(275.0, (float) $sale->fresh()->source()->total_amount);
    }

    public function test_nfe_business_validation_is_visible_and_preserves_the_unsaved_items(): void
    {
        $template = SaleTemplate::create(['company_id' => $this->company->id, 'name' => 'Venda NF-e', 'document_type' => 'nfe', 'stock_control_enabled' => false, 'is_active' => true]);
        $product = Product::factory()->create(['company_id' => $this->company->id, 'created_by' => $this->bpo->id,
            'name' => 'Produto com mínimo', 'sale_price_value' => 50, 'min_sale_price' => 100]);
        $page = Livewire::test(SaleEditor::class)->set('data.sale_template_id', $template->id)
            ->set('data.customer_id', $this->customer->id)
            ->callAction('addItem', ['catalog_id' => $product->id, 'quantity' => 1, 'unit_price' => '50,00', 'discount_amount' => 0]);
        $key = array_key_first($page->get('data.items'));
        $page->call('saveDraft')->assertHasFormErrors(['items'])
            ->assertNotified('Não foi possível salvar o rascunho')
            ->assertSet("data.items.{$key}.catalog_id", $product->id);
        $this->assertDatabaseCount('bpo_sales', 0);
        $this->assertDatabaseCount('requisitions', 0);
        $page->set("data.items.{$key}.unit_price", 100)->call('saveDraft')->assertHasNoFormErrors();
        $this->assertDatabaseCount('bpo_sales', 1);
    }

    public function test_nfe_missing_stock_is_reported_on_the_page_without_losing_items(): void
    {
        $template = SaleTemplate::create(['company_id' => $this->company->id, 'name' => 'NF-e com estoque', 'document_type' => 'nfe', 'stock_control_enabled' => true, 'is_active' => true]);
        $product = Product::factory()->create(['company_id' => $this->company->id, 'created_by' => $this->bpo->id, 'has_stock_control' => true, 'sale_price_value' => 100]);
        $page = Livewire::test(SaleEditor::class)->set('data.sale_template_id', $template->id)
            ->set('data.customer_id', $this->customer->id)
            ->callAction('addItem', ['catalog_id' => $product->id, 'quantity' => 1, 'unit_price' => '100,00', 'discount_amount' => 0]);
        $key = array_key_first($page->get('data.items'));
        $page->call('saveDraft')->assertHasFormErrors(['items'])
            ->assertNotified(Notification::make()->title('Não foi possível salvar o rascunho')
                ->body('Cadastre o estoque do produto antes de usar um modelo com controle de estoque.')->danger())
            ->assertSet("data.items.{$key}.catalog_id", $product->id);
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

    public function test_credit_card_finalization_uses_the_institution_settlement_date_without_assuming_receipt(): void
    {
        $card = CardInstitution::create(['company_id' => $this->company->id, 'name' => 'Adquirente', 'active' => true, 'settlement_days' => 30]);
        $sale = $this->workflow->save($this->bpo, $this->company, $this->payload(), (string) Str::uuid());
        $sale = $this->workflow->finalize($this->bpo, $this->company, $sale->id, $sale->revision, [
            ...$this->payment(), 'payment_method' => Method::CREDIT_CARD->value, 'card_payment_profile_id' => $card->id, 'payment_date' => today()->toDateString(),
        ]);
        $receivable = $sale->invoice->accountReceivables()->sole();
        $this->assertSame($card->id, $receivable->card_payment_profile_id);
        $this->assertSame(today()->addDays(30)->toDateString(), $receivable->installments()->orderBy('due_date')->first()->due_date->toDateString());
        $this->assertSame(0, $receivable->payments()->count());
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_review_page_initializes_dates_and_keeps_the_financial_choice_explicit(): void
    {
        $sale = $this->workflow->save($this->bpo, $this->company, $this->payload(), (string) Str::uuid());
        Livewire::test(SaleEditor::class, ['sale' => $sale->id])
            ->assertSet('data.payment_date', today()->toDateString())->assertSet('data.received_at', today()->toDateString())
            ->assertSet('data.financial_policy', 'create_receivable');
    }

    public function test_explicit_receipt_generates_real_payments_and_cash_once(): void
    {
        $bank = FinancialAccount::create(['company_id' => $this->company->id, 'name' => 'Banco do recebimento', 'type' => 'bank', 'is_active' => true]);
        $sale = $this->workflow->save($this->bpo, $this->company, $this->payload(), (string) Str::uuid());
        $settings = [...$this->payment(), 'mark_as_received' => true, 'received_at' => today()->toDateString(), 'financial_account_id' => $bank->id];
        $sale = $this->workflow->finalize($this->bpo, $this->company, $sale->id, $sale->revision, $settings);
        $receivable = $sale->invoice->accountReceivables()->sole();
        $this->assertSame(190.0, (float) $receivable->payments()->sum('amount') / 100);
        $this->assertSame(190.0, (float) CashMovement::query()->get()->sum('amount'));
        $before = $receivable->payments()->count();
        $this->workflow->finalize($this->bpo, $this->company, $sale->id, $sale->revision, $settings);
        $this->assertSame($before, $receivable->payments()->count());
    }

    public function test_bank_charge_options_are_persisted_only_when_selected(): void
    {
        Bus::fake();
        $bank = FinancialAccount::create(['company_id' => $this->company->id, 'name' => 'Banco de cobrança', 'type' => 'bank', 'is_active' => true]);
        foreach ([Method::BANK_SLIP->value => 'auto_bank_slip_issuance', Method::PIX->value => 'auto_pix_charge_issuance'] as $method => $flag) {
            $sale = $this->workflow->save($this->bpo, $this->company, $this->payload(), (string) Str::uuid());
            $sale = $this->workflow->finalize($this->bpo, $this->company, $sale->id, $sale->revision, [...$this->payment(), 'payment_method' => $method, $flag => true, 'financial_account_id' => $bank->id]);
            $this->assertTrue($sale->invoice->$flag);
            $this->assertSame(0, $sale->invoice->accountReceivables()->sole()->payments()->count());
        }
        Bus::assertDispatched(ScheduleBankSlipIssuanceJob::class);
        Bus::assertDispatched(SchedulePixChargeIssuanceJob::class);
    }

    public function test_banking_options_cannot_bypass_the_operators_issue_permission(): void
    {
        $bank = FinancialAccount::create(['company_id' => $this->company->id, 'name' => 'Banco', 'type' => 'bank', 'is_active' => true]);
        $sale = $this->workflow->save($this->bpo, $this->company, $this->payload(), (string) Str::uuid());
        $role = CompanyRoleAssignment::where('user_id', $this->bpo->id)->sole()->role;
        $permissions = $role->permissions;
        unset($permissions['account_receivables.issue']);
        $role->update(['permissions' => $permissions]);
        try {
            $this->workflow->finalize($this->bpo, $this->company, $sale->id, $sale->revision, [...$this->payment(), 'auto_pix_charge_issuance' => true, 'financial_account_id' => $bank->id]);
            $this->fail('A geração de cobrança exige permissão específica.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertNull($sale->fresh()->finalized_at);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_finalized_sale_summary_contains_items_and_links_to_its_real_documents(): void
    {
        $sale = $this->workflow->save($this->bpo, $this->company, $this->payload(), (string) Str::uuid());
        $sale = $this->workflow->finalize($this->bpo, $this->company, $sale->id, $sale->revision, $this->payment());
        $page = Livewire::test(Sales::class)->mountAction(TestAction::make('details')->table($sale));
        $html = $page->instance()->getMountedAction()->getModalContent()->render();
        $this->assertStringContainsString($this->service->name, $html);
        $this->assertStringContainsString('Abrir fatura', $html);
        $this->assertStringContainsString('Abrir recebível', $html);
        $this->assertStringContainsString('Documentos fiscais', $html);
        $this->get(InvoiceResource::getUrl('view', ['record' => $sale->invoice], panel: 'bpo', tenant: $this->company))->assertOk();
    }

    public function test_invoice_delivery_uses_existing_pdf_service_and_email_job(): void
    {
        $sale = $this->reportSale(190);
        $this->mock(InvoiceService::class, fn ($mock) => $mock->shouldReceive('pdf')->once()->andReturn(base64_encode('%PDF-1.4')));
        Livewire::test(Sales::class)->callAction(TestAction::make('invoiceDownload')->table($sale))->assertFileDownloaded('fatura-'.$sale->invoice->invoice_number.'.pdf');
        Bus::fake([SendInvoiceEmailJob::class]);
        Livewire::test(Sales::class)->callAction(TestAction::make('invoiceEmail')->table($sale), data: ['subject' => 'Documentos da venda', 'body' => 'Segue a documentação.'])->assertHasNoActionErrors();
        Bus::assertDispatched(SendInvoiceEmailJob::class);
    }

    public function test_invoice_bulk_exports_are_available_in_bpo_and_enforce_the_export_scope(): void
    {
        $sale = $this->reportSale(190);
        Livewire::test(ListInvoices::class)->selectTableRecords([$sale->invoice_id])
            ->callAction(TestAction::make('exportSelectedInvoices')->table()->bulk(), data: ['columns' => ['invoice_number', 'net_value']])->assertFileDownloaded();
        $role = CompanyRoleAssignment::where('user_id', $this->bpo->id)->sole()->role;
        $permissions = $role->permissions;
        unset($permissions['invoices.export']);
        $role->update(['permissions' => $permissions]);
        Livewire::test(ListInvoices::class)->assertActionHidden(TestAction::make('exportSelectedInvoices')->table()->bulk());
    }

    public function test_invoice_export_totals_are_batched_and_preserve_discounts_and_travel_without_source_view_grants(): void
    {
        $travelSale = $this->reportSale(190, travel: 12.34);
        $discountSale = $this->workflow->save($this->bpo, $this->company, $this->payload(), (string) Str::uuid());
        $discountSale = $this->workflow->finalize($this->bpo, $this->company, $discountSale->id, $discountSale->revision, $this->payment());
        $invoices = Invoice::whereIn('id', [$travelSale->invoice_id, $discountSale->invoice_id])->get();
        $role = CompanyRoleAssignment::where('user_id', $this->bpo->id)->sole()->role;
        $permissions = $role->permissions;
        unset($permissions['service_orders.view'], $permissions['requisitions.view']);
        $role->update(['permissions' => $permissions]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        Invoice::preloadAmounts($invoices);
        $this->assertSame(202.34, (float) $invoices->firstWhere('id', $travelSale->invoice_id)->net_value);
        $discount = $invoices->firstWhere('id', $discountSale->invoice_id);
        $this->assertSame(200.0, (float) $discount->gross_amount);
        $this->assertSame(10.0, (float) $discount->discount_amount);
        $this->assertSame(190.0, (float) $discount->net_value);
        // Confirmed totals are loaded in one batch, without consulting mutable sources.
        $this->assertCount(1, DB::getQueryLog());
        DB::disableQueryLog();
    }
}
