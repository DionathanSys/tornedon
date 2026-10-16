<?php

namespace Tests\Feature\Services\Company;

use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\FiscalDocument;
use App\Models\Invoice;
use App\Models\Partner;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ServiceOrder;
use App\Services\AccountReceivable\AccountReceivableGenerationService;
use App\Services\Company\CompanyOperationalResetService;
use App\Services\CompanyCard\CompanyCardTransactionService;
use App\Services\FiscalDocument\Actions\GenerateFiscalEntryCardTransactionAction;
use App\Services\FiscalDocument\Actions\GenerateFiscalEntryPayableAction;
use App\Services\FiscalDocument\Actions\ProcessFiscalEntryStockAction;
use App\Services\FiscalDocument\Actions\ProcessPurchaseReturnStockAction;
use App\Services\FiscalDocument\Actions\ReversePurchaseReturnStockAction;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class CompanyOperationalResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_reports_ids_balances_and_fiscal_decisions_without_writes_or_api_calls(): void
    {
        Http::preventStrayRequests();
        $data = $this->fixture();
        $before = $this->snapshot();

        $plan = app(CompanyOperationalResetService::class)->plan($data['company']->id);

        $this->assertSame([], $plan['blockers']);
        $this->assertContains($data['invoice']->id, $plan['delete']['invoices']);
        $this->assertSame(125.0, $plan['balances']['financial_accounts'][0]['opening_balance']);
        $this->assertSame(175.0, $plan['balances']['financial_accounts'][0]['current_balance']);
        $this->assertSame(3, $plan['preserve']['fiscal_documents']);
        $this->assertSame($before, $this->snapshot());
        $this->artisan('company:reset-operations', ['company' => $data['company']->id, '--dry-run' => true])
            ->expectsOutputToContain('Validar antes de executar:')->assertSuccessful();
        $this->assertSame($before, $this->snapshot());
        Http::assertNothingSent();
    }

    public function test_reset_preserves_fiscal_history_and_catalogues_and_isolates_other_company(): void
    {
        $data = $this->fixture();
        $other = $this->fixture();
        $otherBefore = $this->snapshot($other['company']->id);
        $service = app(CompanyOperationalResetService::class);

        $service->execute($data['company']->id);

        foreach (['invoices', 'service_orders', 'cash_movements'] as $table) {
            $this->assertDatabaseMissing($table, ['company_id' => $data['company']->id]);
        }
        $this->assertDatabaseMissing('fiscal_documents', ['id' => $data['draft']->id]);
        $this->assertDatabaseMissing('fiscal_document_items', ['fiscal_document_id' => $data['draft']->id]);
        foreach ($data['documents'] as $doc) {
            $this->assertDatabaseHas('fiscal_documents', ['id' => $doc->id, 'invoice_id' => null, 'nfe_status' => $doc->getRawOriginal('nfe_status')]);
            $this->assertNotNull($doc->fresh()->operational_reset_at);
            $this->assertDatabaseHas('fiscal_document_items', ['fiscal_document_id' => $doc->id]);
            $this->assertDatabaseHas('fiscal_document_payloads', ['fiscal_document_id' => $doc->id]);
        }
        $this->assertDatabaseHas('attachments', ['id' => $data['fiscalAttachment']]);
        $this->assertDatabaseMissing('attachments', ['id' => $data['orderAttachment']]);
        $this->assertDatabaseHas('products', ['id' => $data['product']->id]);
        $this->assertDatabaseHas('company_partner', ['company_id' => $data['company']->id, 'partner_id' => $data['customer']->id]);
        $this->assertDatabaseHas('nfe_sequences', ['company_id' => $data['company']->id, 'last_number' => 100]);
        $this->assertDatabaseHas('product_stocks', [
            'id' => $data['stock']->id, 'quantity_total' => 0, 'quantity_reserved' => 0, 'quantity_minimum' => 2,
        ]);
        $this->assertSame(0.0, $data['account']->fresh()->current_balance);
        $this->assertSame($otherBefore, $this->snapshot($other['company']->id));

        // An old in-memory instance must see the marker written by the reset too.
        $this->assertTrue($data['documents'][0]->isOperationalHistory());
        $this->assertTrue(app(AccountReceivableGenerationService::class)->generateFromFiscalDocument($data['documents'][0]));
        $this->assertSame(['stock_movements' => 0, 'errors' => []], app(ProcessFiscalEntryStockAction::class)->execute($data['documents'][0], 1));
        $this->assertDatabaseCount('account_receivables', 0);
        $this->assertDatabaseCount('stock_movements', 0);

        // A second execution is harmless and does not renumber preserved history.
        $service->execute($data['company']->id);
        $this->assertSame($otherBefore, $this->snapshot($other['company']->id));
    }

    public function test_uncertain_fiscal_status_blocks_every_write(): void
    {
        $data = $this->fixture();
        DB::table('fiscal_documents')->where('id', $data['draft']->id)->update(['nfe_status' => 'in_processing']);
        $before = $this->snapshot();
        $service = app(CompanyOperationalResetService::class);

        $this->assertStringContainsString('#'.$data['draft']->id, implode(' ', $service->plan($data['company']->id)['blockers']));
        try {
            $service->execute($data['company']->id);
            $this->fail('Should refuse unresolved fiscal documents.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('consultar/conciliar', $e->getMessage());
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_nfse_uses_its_own_status_when_preserving_history_and_removing_drafts(): void
    {
        $data = $this->fixture();
        foreach ([...$data['documents'], $data['draft']] as $doc) {
            DB::table('fiscal_documents')->where('id', $doc->id)->update([
                'document_type' => 'nfse', 'nfe_status' => null, 'nfse_status' => $doc->getRawOriginal('nfe_status'),
            ]);
        }

        $plan = app(CompanyOperationalResetService::class)->execute($data['company']->id);

        $this->assertSame([], $plan['blockers']);
        $this->assertSame(3, $plan['preserve']['fiscal_documents']);
        $this->assertDatabaseMissing('fiscal_documents', ['id' => $data['draft']->id]);
        foreach ($data['documents'] as $doc) {
            $this->assertDatabaseHas('fiscal_documents', [
                'id' => $doc->id, 'document_type' => 'nfse', 'nfse_status' => $doc->getRawOriginal('nfe_status'),
            ]);
        }
    }

    public function test_cross_tenant_reference_is_reported_and_never_detached_or_deleted(): void
    {
        $data = $this->fixture();
        $other = $this->fixture();
        DB::table('fiscal_documents')->where('id', $other['documents'][0]->id)->update(['invoice_id' => $data['invoice']->id]);
        $before = $this->snapshot();
        try {
            app(CompanyOperationalResetService::class)->execute($data['company']->id);
            $this->fail('Should refuse cross-tenant references.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('fora da empresa', $e->getMessage());
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_unknown_cascading_dependency_blocks_instead_of_silently_deleting_data(): void
    {
        $data = $this->fixture();
        $before = $this->snapshot();
        Schema::create('retained_invoice_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
        });
        try {
            DB::table('retained_invoice_records')->insert(['invoice_id' => $data['invoice']->id]);
            try {
                app(CompanyOperationalResetService::class)->execute($data['company']->id);
                $this->fail('An unknown dependency must block the reset even if the FK cascades.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('retained_invoice_records.invoice_id', $e->getMessage());
            }
            $this->assertDatabaseCount('retained_invoice_records', 1);
            $this->assertSame($before, $this->snapshot());
        } finally {
            Schema::dropIfExists('retained_invoice_records');
        }
    }

    public function test_execute_requires_maintenance_and_stopped_workers(): void
    {
        $company = Company::factory()->create();
        $this->artisan('company:reset-operations', ['company' => $company->id, '--execute' => true])
            ->expectsOutputToContain('coloque a aplicação em manutenção')->assertFailed();
    }

    public function test_command_executes_only_after_interactive_company_confirmation(): void
    {
        $data = $this->fixture();
        $id = $data['company']->id;
        $this->mock(MaintenanceMode::class)
            ->shouldReceive('active')->andReturn(true);

        $this->artisan('company:reset-operations', ['company' => $id, '--execute' => true, '--workers-stopped' => true])
            ->expectsQuestion("Backup realizado? Para excluir as operações, digite o ID da empresa ({$id})", (string) $id)
            ->assertSuccessful();

        $this->assertDatabaseMissing('invoices', ['company_id' => $id]);
        $this->assertSame(0.0, $data['account']->fresh()->current_balance);
    }

    public function test_old_fiscal_webhooks_update_status_without_recreating_stock_or_financial_data(): void
    {
        $data = $this->fixture();
        $document = $data['documents'][0];
        $key = str_pad((string) $document->id, 44, '0', STR_PAD_LEFT);
        DB::table('fiscal_documents')->where('id', $document->id)->update(['document_key' => $key]);
        app(CompanyOperationalResetService::class)->execute($data['company']->id);

        $this->postJson(route('webhook.nfe'), [
            'chave' => $key, 'status' => 'autorizado', 'protocolo' => 'historical-protocol',
        ])->assertOk();
        $this->assertSame('historical-protocol', $document->fresh()->nfe_protocolo);
        $this->postJson(route('webhook.nfe'), ['chave' => $key, 'status' => 'cancelado'])->assertOk();
        $this->assertSame('canceled', $document->fresh()->nfe_status->value);
        $this->assertDatabaseCount('account_receivables', 0);
        $this->assertDatabaseCount('account_payables', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame('0.000', $data['stock']->fresh()->quantity_total);
    }

    public function test_database_failure_rolls_back_deletes_detachments_and_history_markers(): void
    {
        $data = $this->fixture();
        $before = $this->snapshot();
        DB::unprepared("CREATE TRIGGER reject_invoice_delete BEFORE DELETE ON invoices BEGIN SELECT RAISE(ABORT, 'simulated failure'); END");
        try {
            app(CompanyOperationalResetService::class)->execute($data['company']->id);
            $this->fail('Should fail and roll back the transaction.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('simulated failure', $e->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER reject_invoice_delete');
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_historical_entry_and_return_actions_do_not_rebuild_payables_cards_or_stock(): void
    {
        $data = $this->fixture();
        app(CompanyOperationalResetService::class)->execute($data['company']->id);
        $document = $data['documents'][0];

        $this->assertSame(['payables' => 0, 'errors' => []], app(GenerateFiscalEntryPayableAction::class)->execute($document, [], 1));
        $this->assertSame(['transactions' => 0, 'errors' => []], app(GenerateFiscalEntryCardTransactionAction::class)->execute($document, [], 1));
        $this->assertSame([], app(CompanyCardTransactionService::class)->createFromFiscalDocument($document, [], 1));
        $this->assertSame(['stock_movements' => 0, 'errors' => []], app(ProcessPurchaseReturnStockAction::class)->execute($document, 1));
        $this->assertSame(['reversed_movements' => 0, 'errors' => []], app(ReversePurchaseReturnStockAction::class)->execute($document, 1));
        foreach (['account_payables', 'company_card_transactions', 'stock_movements'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    private function fixture(): array
    {
        $company = Company::factory()->create();
        $customer = Partner::factory()->create();
        $company->partners()->attach($customer, ['type' => 'customer']);
        $product = Product::factory()->create(['company_id' => $company->id, 'has_stock_control' => true]);
        $stock = ProductStock::create([
            'company_id' => $company->id, 'product_id' => $product->id, 'quantity_total' => 20,
            'quantity_reserved' => 3, 'quantity_minimum' => 2, 'average_cost' => 10,
        ]);
        $account = FinancialAccount::create(['company_id' => $company->id, 'name' => 'Banco', 'type' => 'bank', 'opening_balance' => 125]);
        DB::table('cash_movements')->insert([
            'company_id' => $company->id, 'financial_account_id' => $account->id,
            'direction' => 'inflow', 'transaction_date' => now()->toDateString(), 'amount' => 5000, 'description' => 'Entrada',
        ]);
        $invoice = Invoice::create([
            'company_id' => $company->id, 'customer_id' => $customer->id, 'invoice_number' => '1',
            'invoice_date' => now()->toDateString(), 'status' => 'pending',
        ]);
        $order = ServiceOrder::factory()->create(['company_id' => $company->id, 'customer_id' => $customer->id, 'invoice_id' => $invoice->id]);
        $documents = [];
        foreach (['authorized', 'canceled', 'rejected', 'pending'] as $index => $status) {
            $doc = FiscalDocument::create([
                'company_id' => $company->id, 'customer_id' => $customer->id, 'invoice_id' => $invoice->id,
                'status' => 'pending', 'document_type' => 'nfe', 'nfe_status' => $status,
                'issued_at' => now()->toDateString(), 'movement_at' => now()->toDateString(),
                'document_number' => ($company->id * 10) + $index, 'document_series' => '1',
            ]);
            DB::table('fiscal_document_items')->insert([
                'fiscal_document_id' => $doc->id, 'product_id' => $product->id, 'item_number' => 1,
                'quantity' => 1, 'unit_of_measure' => 'UN', 'unit_price' => 10, 'total_price' => 10,
            ]);
            DB::table('fiscal_document_payloads')->insert(['company_id' => $company->id, 'fiscal_document_id' => $doc->id, 'nfe_payload' => '{}']);
            if ($status === 'pending') {
                $draft = $doc;
            } else {
                $documents[] = $doc;
            }
        }
        DB::table('nfe_sequences')->insert(['company_id' => $company->id, 'serie' => '1', 'operation_nature' => 'VENDA', 'last_number' => 100]);
        $fiscalAttachment = $this->attachment($company->id, $documents[0]);
        $orderAttachment = $this->attachment($company->id, $order);

        return compact('company', 'customer', 'product', 'stock', 'account', 'invoice', 'order', 'documents', 'draft', 'fiscalAttachment', 'orderAttachment');
    }

    private function attachment(int $companyId, $model): int
    {
        return DB::table('attachments')->insertGetId([
            'company_id' => $companyId, 'public_id' => (string) Str::ulid(),
            'attachable_type' => $model->getMorphClass(), 'attachable_id' => $model->id,
            'type' => 'xml', 'disk' => 'local', 'path' => Str::uuid().'.xml',
            'original_name' => 'document.xml', 'stored_name' => 'document.xml', 'size_bytes' => 10,
        ]);
    }

    private function snapshot(?int $companyId = null): array
    {
        $result = [];
        foreach (['companies', 'company_partner', 'products', 'product_stocks', 'financial_accounts', 'cash_movements',
            'invoices', 'service_orders', 'fiscal_documents', 'attachments', 'nfe_sequences'] as $table) {
            $query = DB::table($table)->orderBy('id');
            if ($companyId) {
                $query->where($table === 'companies' ? 'id' : 'company_id', $companyId);
            }
            $result[$table] = $query->get()->map(fn ($row) => (array) $row)->all();
        }

        return $result;
    }
}
