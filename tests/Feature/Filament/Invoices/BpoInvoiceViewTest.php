<?php

namespace Tests\Feature\Filament\Invoices;

use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\NfeStatus;
use App\Enum\Invoice\Status;
use App\Filament\Bpo\Resources\Invoices\Pages\ViewInvoice;
use App\Filament\Bpo\Resources\Invoices\RelationManagers\ServiceOrdersRelationManager;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Partner;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\Access\CompanyTeamService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class BpoInvoiceViewTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Invoice $invoice;

    private User $owner;

    private User $bpo;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::factory()->create();
        $bpo = User::factory()->create();
        $this->owner = $owner;
        $this->bpo = $bpo;
        $this->company = Company::factory()->create();
        $owner->companies()->attach($this->company, ['role' => 'owner', 'is_active' => true]);
        $teams = app(CompanyTeamService::class);
        $role = $teams->saveRole($owner, $this->company, [
            'name' => 'Consulta de faturas',
            'permissions' => ['invoices.view' => 'company', 'service_orders.view' => 'company'],
            'panels' => ['bpo'],
        ]);
        $teams->authorizeBpo($owner, $this->company, $bpo, [$role->id], false, []);
        $this->invoice = Invoice::create([
            'company_id' => $this->company->id,
            'customer_id' => Partner::factory()->create()->id,
            'invoice_number' => 'BPO-001',
            'invoice_date' => today(),
            'status' => Status::PENDING,
            'created_by' => $owner->id,
        ]);

        $this->actingAs($this->bpo);
        Filament::setCurrentPanel('bpo');
        Filament::setTenant($this->company);
        Livewire::withoutLazyLoading();
    }

    public function test_bpo_shows_shared_invoice_details_and_all_relationship_tabs(): void
    {
        Livewire::test(ViewInvoice::class, ['record' => $this->invoice->id])
            ->assertSuccessful()
            ->assertSee($this->invoice->customer->name)
            ->assertSee('Dados da Fatura')
            ->assertSee('Criado por')
            ->assertSee('Forma de Pagamento')
            ->assertSee('Condição de Pagamento')
            ->assertSee('Categoria Financeira')
            ->assertSee('Valor de Serviços')
            ->assertSee('Valor de Produtos')
            ->assertSee('Desconto')
            ->assertSee('Contas')
            ->assertSee('Itens')
            ->assertSee('Anexos')
            ->assertSee('Histórico da composição')
            ->set('activeRelationManager', '1')
            ->assertSet('activeRelationManager', '1')
            ->set('activeRelationManager', '2')
            ->assertSee('Anexos')
            ->set('activeRelationManager', '3')
            ->assertSee('Histórico da composição');
    }

    public function test_related_order_totals_do_not_query_items_per_row_and_have_no_mutating_actions(): void
    {
        $this->actingAs($this->owner);
        Filament::setCurrentPanel('admin');
        $orders = ServiceOrder::factory()->count(5)->create([
            'company_id' => $this->company->id,
            'customer_id' => $this->invoice->customer_id,
            'invoice_id' => $this->invoice->id,
            'travel_value' => 12.34,
        ]);

        $this->actingAs($this->bpo);
        Filament::setCurrentPanel('bpo');

        $component = Livewire::test(ServiceOrdersRelationManager::class, [
            'ownerRecord' => $this->invoice,
            'pageClass' => ViewInvoice::class,
        ])->call('loadTable')->assertCanSeeTableRecords($orders)->assertTableActionHidden('removeFromDraft', $orders->first());

        $component->instance()->flushCachedTableRecords();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $records = $component->instance()->getTableRecords();
        foreach ($records as $record) {
            $this->assertSame(12.34, $record->total_amount);
            $this->assertSame(0.0, $record->discount_amount);
        }
        $itemQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'service_order_items'));
        DB::disableQueryLog();
        $this->assertSame(1, $itemQueries->count());
    }

    public function test_bpo_cannot_mount_relationships_for_another_company(): void
    {
        $this->invoice->company_id = $this->company->id + 999;

        Livewire::test(ServiceOrdersRelationManager::class, [
            'ownerRecord' => $this->invoice,
            'pageClass' => ViewInvoice::class,
        ])->assertForbidden();
    }

    public function test_fiscal_polling_checks_status_without_loading_documents(): void
    {
        $this->actingAs($this->owner);
        Filament::setCurrentPanel('admin');
        $document = $this->invoice->fiscalDocuments()->create([
            'company_id' => $this->company->id,
            'customer_id' => $this->invoice->customer_id,
            'document_type' => DocumentModel::NFSE,
            'status' => 'pending',
            'issued_at' => today(),
            'movement_at' => today(),
            'nfse_status' => NfeStatus::QUEUED,
        ]);

        $this->assertTrue($this->invoice->hasProcessingFiscalDocuments());
        $this->assertFalse($this->invoice->relationLoaded('fiscalDocuments'));
        $document->update(['nfse_status' => NfeStatus::AUTHORIZED]);
        $this->assertFalse($this->invoice->hasProcessingFiscalDocuments());
        $document->update(['nfe_status' => NfeStatus::IN_PROCESSING]);
        $this->assertTrue($this->invoice->hasProcessingFiscalDocuments());

        $other = $this->invoice->replicate();
        $other->invoice_number = 'BPO-002';
        $other->save();
        $this->assertFalse($other->hasProcessingFiscalDocuments());
    }
}
