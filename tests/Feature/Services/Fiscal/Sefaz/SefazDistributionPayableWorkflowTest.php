<?php

namespace Tests\Feature\Services\Fiscal\Sefaz;

use App\Filament\Clusters\Financial\Resources\FiscalDocuments\Pages\EditFiscalDocument;
use App\Filament\Clusters\Financial\Resources\SefazDistributionDocuments\Actions\GenerateAccountPayableAction;
use App\Filament\Clusters\Financial\Resources\SefazDistributionDocuments\Actions\LinkAccountPayableAction;
use App\Filament\Clusters\Financial\Resources\SefazDistributionDocuments\Pages\ListSefazDistributionDocuments;
use App\Filament\Clusters\Financial\Resources\SefazDistributionDocuments\Pages\ViewSefazDistributionDocument;
use App\Models\AccountPayable;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\FiscalDocument;
use App\Models\Partner;
use App\Models\Product;
use App\Models\ResultCenter;
use App\Models\SefazDistributionDocument;
use App\Models\User;
use App\Services\Access\CompanyTeamService;
use App\Services\AccountPayable\AccountPayableService;
use App\Services\Fiscal\Sefaz\GenerateSefazDistributionPayableAction;
use App\Services\Fiscal\Sefaz\SefazDistributionFinancialLinkService;
use App\Services\Fiscal\Sefaz\SefazDistributionFiscalDocumentImportService;
use App\Services\Fiscal\Sefaz\SefazDistributionPayablePlan;
use App\Services\FiscalDocument\Actions\GenerateFiscalEntryPayableAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class SefazDistributionPayableWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private Partner $supplier;

    private SefazDistributionDocument $dfe;

    protected function setUp(): void
    {
        parent::setUp();
        $compiledPath = '/tmp/opencode/dfe-workflow-'.Str::uuid();
        File::ensureDirectoryExists($compiledPath);
        config(['view.compiled' => $compiledPath]);
        (new \ReflectionProperty(app('blade.compiler'), 'cachePath'))->setValue(app('blade.compiler'), $compiledPath);
        Storage::set('local', Storage::fake('dfe-workflow-'.Str::uuid()));
        $this->user = User::factory()->create();
        $this->company = Company::factory()->create();
        $this->user->companies()->attach($this->company, ['role' => 'owner', 'is_active' => true]);
        $this->supplier = Partner::create(['name' => 'Fornecedor DF-e', 'document_type' => 'CNPJ', 'document_number' => '12345678000199']);
        $this->supplier->companies()->attach($this->company, ['is_active' => true, 'type' => '["supplier"]']);
        $product = Product::create(['company_id' => $this->company->id, 'product_code' => 'P001', 'name' => 'Produto DF-e', 'unit' => 'UN', 'has_stock_control' => false, 'is_active' => true, 'created_by' => $this->user->id]);
        $this->dfe = SefazDistributionDocument::create([
            'company_id' => $this->company->id, 'partner_id' => $this->supplier->id,
            'document_key' => '35260412345678000199550010000003211000000321',
            'nsu' => '000000000000001', 'document_type' => 'nfe', 'document_number' => '321',
            'status' => 'full_xml_available', 'manifestation_status' => 'accepted', 'import_status' => 'ready_to_import', 'full_xml_available' => true,
            'full_xml_path' => 'sefaz/workflow.xml', 'issued_at' => '2026-04-19', 'total_amount' => 150.99,
            'items_json' => [['line' => 1, 'product_id' => $product->id]],
        ]);
        $this->writeXml();
        $this->actingAs($this->user);
        Filament::setCurrentPanel('admin');
        Filament::setTenant($this->company);
    }

    public function test_importing_before_generating_financial_links_both_records(): void
    {
        $note = $this->importNote();
        $payable = $this->generate();
        $this->assertSame($note->id, $payable->fiscal_document_id);
        $this->assertSame($payable->id, $this->dfe->fresh()->account_payable_id);
        $this->assertSame('account_payable', $note->fresh()->financial_control_mode);
        $this->assertDatabaseCount('account_payables', 1);
    }

    public function test_generation_finds_a_separately_registered_note_by_company_and_access_key(): void
    {
        $note = $this->importNote();
        $this->dfe->update(['fiscal_document_id' => null]);
        $payable = $this->generate();
        $this->assertSame($note->id, $payable->fiscal_document_id);
        $this->assertSame($note->id, $this->dfe->fresh()->fiscal_document_id);
    }

    public function test_financial_created_from_note_is_recognized_by_dfe_and_reused(): void
    {
        $note = $this->importNote();
        $result = app(GenerateFiscalEntryPayableAction::class)->execute($note, $this->paymentData(), $this->user->id);
        $this->assertSame(1, $result['payables']);
        $payable = $this->generate();
        $this->assertSame($payable->id, $this->dfe->fresh()->account_payable_id);
        $this->assertDatabaseCount('account_payables', 1);
    }

    public function test_confirmation_processes_the_entry_with_existing_financial_and_is_idempotent(): void
    {
        $payable = $this->generate();
        $note = $this->importNote();
        Livewire::test(EditFiscalDocument::class, ['record' => $note->id])
            ->callAction('confirmEntry', data: ['generate_account_payable_now' => false])
            ->assertHasNoActionErrors()->assertNotified()->assertActionHidden('confirmEntry');
        $this->assertTrue($note->fresh()->confirmed);
        $this->assertSame('account_payable', $note->fresh()->financial_control_mode);
        $this->assertSame($note->id, $payable->fresh()->fiscal_document_id);
        Livewire::test(EditFiscalDocument::class, ['record' => $note->id])->assertActionHidden('confirmEntry');
        $this->assertDatabaseCount('account_payables', 1);
    }

    public function test_xml_duplicates_preserve_actual_dates_and_unequal_values(): void
    {
        $this->writeXml('<cobr><fat><vLiq>150.99</vLiq></fat><dup><nDup>001</nDup><dVenc>2026-05-12</dVenc><vDup>50.00</vDup></dup><dup><nDup>002</nDup><dVenc>2026-07-03</dVenc><vDup>100.99</vDup></dup></cobr>');
        $payable = $this->generate(['installments_source' => 'xml']);
        $rows = $payable->installments()->orderBy('sequence_number')->get();
        $this->assertSame(['2026-05-12', '2026-07-03'], $rows->map(fn ($row) => $row->due_date->toDateString())->all());
        $this->assertSame([50.0, 100.99], $rows->map(fn ($row) => (float) $row->due_amount)->all());
        $this->assertSame('Duplicata 002', $rows[1]->description);
    }

    public function test_single_xml_duplicate_is_normalized_as_one_installment(): void
    {
        $this->writeXml('<cobr><dup><nDup>001</nDup><dVenc>2026-06-10</dVenc><vDup>150.99</vDup></dup></cobr>');
        $payable = $this->generate(['installments_source' => 'xml']);
        $this->assertSame('2026-06-10', $payable->installments()->sole()->due_date->toDateString());
    }

    public function test_manual_installments_carry_classification_and_automatic_payment_settings(): void
    {
        $category = FinancialCategory::create(['company_id' => $this->company->id, 'name' => 'Compras', 'allow_payable' => true, 'is_active' => true]);
        $cost = CostCenter::create(['company_id' => $this->company->id, 'name' => 'Produção', 'is_active' => true]);
        $result = ResultCenter::create(['company_id' => $this->company->id, 'name' => 'Operação', 'is_active' => true]);
        $bank = FinancialAccount::create(['company_id' => $this->company->id, 'name' => 'Banco', 'type' => 'bank', 'is_active' => true]);
        $payable = $this->generate([
            'installments_source' => 'manual', 'due_amount' => 150.99,
            'installments' => [['due_date' => '2026-05-10', 'due_amount' => 30, 'notes' => 'Entrada'], ['due_date' => '2026-08-25', 'due_amount' => 120.99]],
            'financial_category_id' => $category->id, 'cost_center_id' => $cost->id, 'result_center_id' => $result->id,
            'auto_register_payment_on_due_date' => true, 'auto_payment_financial_account_id' => $bank->id,
            'bank_slip_number' => 'BOLETO-321',
        ]);
        $this->assertTrue($payable->auto_register_payment_on_due_date);
        $this->assertSame($bank->id, $payable->auto_payment_financial_account_id);
        $this->assertSame('BOLETO-321', $payable->bank_slip_number);
        foreach ($payable->installments as $row) {
            $this->assertSame($category->id, $row->financial_category_id);
            $this->assertSame($cost->id, $row->cost_center_id);
            $this->assertSame($result->id, $row->result_center_id);
        }
        $this->assertSame(150.99, (float) $payable->due_amount);
    }

    public function test_mismatched_installments_are_rejected_without_partial_records(): void
    {
        try {
            $this->generate(['installments_source' => 'manual', 'installments' => [['due_date' => '2026-05-10', 'due_amount' => 100]]]);
            $this->fail('Deveria rejeitar parcelas com soma diferente do total.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('installments', $exception->errors());
        }
        $this->assertDatabaseCount('account_payables', 0);
        $this->assertNull($this->dfe->fresh()->account_payable_id);
    }

    public function test_difference_from_fiscal_amount_requires_reason_and_is_audited(): void
    {
        try {
            $this->generate(['due_amount' => 140]);
            $this->fail('Deveria exigir justificativa.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('amount_difference_reason', $exception->errors());
        }
        $payable = $this->generate(['due_amount' => 140, 'amount_difference_reason' => 'Abatimento negociado com fornecedor']);
        $this->assertSame(140.0, (float) $payable->due_amount);
        $this->assertDatabaseHas('audit_entries', ['event' => 'sefaz_distribution.account_payable_generated']);
    }

    public function test_fixed_day_plan_handles_short_months_and_rounding(): void
    {
        $rows = app(SefazDistributionPayablePlan::class)->calculate($this->dfe, [
            'due_amount' => 100, 'payment_condition' => 'parcelado_3x', 'due_date' => '2026-01-31',
            'installment_due_mode' => 'fixed_day_of_month', 'installment_fixed_day' => 31,
        ]);
        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31'], array_column($rows, 'due_date'));
        $this->assertSame([33.33, 33.33, 33.34], array_column($rows, 'due_amount'));
    }

    public function test_foreign_account_cannot_be_linked(): void
    {
        $other = Company::factory()->create();
        $payable = $this->existingAccount(['company_id' => $other->id]);
        $this->expectException(ValidationException::class);
        app(SefazDistributionFinancialLinkService::class)->synchronize($this->dfe, $payable);
    }

    public function test_repair_command_supports_dry_run_and_preserves_installments(): void
    {
        $note = $this->importNote();
        $payable = $this->generate();
        $payable->update(['fiscal_document_id' => null]);
        $this->artisan('sefaz:repair-financial-links', ['company' => $this->company->id, '--dry-run' => true])->assertSuccessful();
        $this->assertNull($payable->fresh()->fiscal_document_id);
        $this->artisan('sefaz:repair-financial-links', ['company' => $this->company->id])->assertSuccessful();
        $this->assertSame($note->id, $payable->fresh()->fiscal_document_id);
        $this->assertSame(150.99, (float) $payable->fresh()->due_amount);
        $this->assertDatabaseCount('account_payable_installments', 1);
    }

    public function test_filament_modal_generates_reviewed_installments_and_displays_financial_summary(): void
    {
        Livewire::test(ListSefazDistributionDocuments::class)
            ->callAction(TestAction::make('generateAccountPayable')->table($this->dfe), data: [
                ...$this->paymentData(), 'installments_source' => 'manual', 'due_amount' => '1.234,56',
                'amount_difference_reason' => 'Valor negociado com o fornecedor',
                'installments' => [['due_date' => '2026-05-10', 'due_amount' => '1.000,00'], ['due_date' => '2026-07-15', 'due_amount' => '234,56']],
            ])->assertHasNoActionErrors()->assertNotified()
            ->assertSee('Em aberto')->assertSee('1.234,56')->assertSee('10/05/2026');
        $this->assertDatabaseCount('account_payables', 1);
        $this->assertDatabaseCount('account_payable_installments', 2);
        $this->assertSame(1234.56, (float) AccountPayable::query()->sole()->due_amount);
    }

    public function test_filament_can_link_an_existing_account_without_creating_another(): void
    {
        $note = $this->importNote();
        $payable = $this->existingAccount();
        Livewire::test(ListSefazDistributionDocuments::class)
            ->callAction(TestAction::make('linkAccountPayable')->table($this->dfe), data: ['account_payable_id' => $payable->id])
            ->assertHasNoActionErrors()->assertNotified();
        $this->assertSame($note->id, $payable->fresh()->fiscal_document_id);
        $this->assertSame($payable->id, $this->dfe->fresh()->account_payable_id);
        $this->assertDatabaseCount('account_payables', 1);
    }

    public function test_document_details_refresh_financial_links_after_generation(): void
    {
        Livewire::test(ViewSefazDistributionDocument::class, ['record' => $this->dfe->id])
            ->callAction('generateAccountPayable', data: [
                ...$this->paymentData(), 'installments_source' => 'manual', 'due_amount' => '150,99',
                'installments' => [['due_date' => '2026-05-10', 'due_amount' => '150,99']],
            ])->assertHasNoActionErrors()->assertNotified()
            ->assertActionVisible('openAccountPayable')->assertActionHidden('generateAccountPayable')
            ->assertSee('Em aberto')->assertSee('150,99');
    }

    public function test_filament_can_calculate_the_installment_preview_before_submission(): void
    {
        $page = Livewire::test(ListSefazDistributionDocuments::class)
            ->mountAction(TestAction::make('generateAccountPayable')->table($this->dfe))
            ->assertSet('mountedActions.0.data.due_amount', '150,99')
            ->set('mountedActions.0.data.payment_condition', 'parcelado_3x')
            ->set('mountedActions.0.data.due_amount', '1.234,56')
            ->set('mountedActions.0.data.due_date', '2026-01-31')
            ->set('mountedActions.0.data.installment_due_mode', 'fixed_day_of_month')
            ->set('mountedActions.0.data.installment_fixed_day', 31)
            ->callAction(TestAction::make('calculateInstallments')->schemaComponent('calculate_installment_actions', 'mountedActionSchema0'))
            ->assertHasNoActionErrors()
            ->assertSet('mountedActions.0.data.installments.1.due_date', '2026-02-28')
            ->assertSet('mountedActions.0.data.installments.2.due_amount', '411,52');

        $this->assertSame(1234.56, $page->instance()->getSchema('mountedActionSchema0')->getComponent('installments_total')->getState());
    }

    public function test_selecting_xml_duplicates_formats_money_and_preserves_whole_reais_on_submission(): void
    {
        $this->writeXml('<cobr><dup><dVenc>2026-05-10</dVenc><vDup>100.00</vDup></dup><dup><dVenc>2026-06-10</dVenc><vDup>50.99</vDup></dup></cobr>');
        Livewire::test(ListSefazDistributionDocuments::class)
            ->mountAction(TestAction::make('generateAccountPayable')->table($this->dfe))
            ->set('mountedActions.0.data.installments_source', 'manual')
            ->set('mountedActions.0.data.installments_source', 'xml')
            ->assertSet('mountedActions.0.data.installments.0.due_amount', '100,00')
            ->assertSet('mountedActions.0.data.installments.1.due_amount', '50,99')
            ->callMountedAction()->assertHasNoActionErrors()->assertNotified();

        $payable = AccountPayable::query()->sole();
        $this->assertSame(150.99, (float) $payable->due_amount);
        $this->assertSame([100.0, 50.99], $payable->installments()->orderBy('sequence_number')->get()->map(fn ($row) => (float) $row->due_amount)->all());
    }

    public function test_view_only_financial_role_cannot_generate_or_link_accounts(): void
    {
        $teams = app(CompanyTeamService::class);
        $role = $teams->saveRole($this->user, $this->company, [
            'name' => 'Consulta financeira DF-e', 'panels' => ['admin'], 'permissions' => ['account_payables.view' => 'company'],
        ]);
        /** @var User $viewer */
        $viewer = User::factory()->create();
        $teams->assign($this->user, $this->company, $viewer, [$role->id]);
        $this->actingAs($viewer);
        $this->assertFalse(GenerateAccountPayableAction::make()->record($this->dfe)->isAuthorized());
        $this->assertFalse(LinkAccountPayableAction::make()->record($this->dfe)->isAuthorized());
        $this->assertFalse(LinkAccountPayableAction::repair()->record($this->dfe)->isAuthorized());
    }

    public function test_ambiguous_note_accounts_require_explicit_selection(): void
    {
        $note = $this->importNote();
        $first = $this->existingAccount(['fiscal_document_id' => $note->id]);
        $this->existingAccount(['fiscal_document_id' => $note->id]);
        try {
            $this->generate();
            $this->fail('Não deve escolher uma conta arbitrariamente.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('account_payable_id', $exception->errors());
        }
        app(SefazDistributionFinancialLinkService::class)->synchronize($this->dfe, $first);
        $this->assertSame($first->id, $this->dfe->fresh()->account_payable_id);
        $this->assertDatabaseCount('account_payables', 2);
    }

    public function test_a_foreign_classification_rolls_back_the_entire_generation(): void
    {
        $category = FinancialCategory::create(['company_id' => Company::factory()->create()->id, 'name' => 'Categoria alheia', 'allow_payable' => true, 'is_active' => true]);
        try {
            $this->generate(['financial_category_id' => $category->id]);
            $this->fail('Não deve aceitar uma categoria de outra empresa.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Erro ao criar conta', $exception->getMessage());
        }
        $this->assertDatabaseCount('account_payables', 0);
        $this->assertDatabaseCount('account_payable_installments', 0);
    }

    public function test_financial_filters_find_missing_financial_and_incomplete_links(): void
    {
        Livewire::test(ListSefazDistributionDocuments::class)->filterTable('without_financial')->assertCanSeeTableRecords([$this->dfe]);
        $note = $this->importNote();
        $payable = $this->generate();
        $payable->update(['fiscal_document_id' => null]);
        Livewire::test(ListSefazDistributionDocuments::class)
            ->resetTableFilters()->filterTable('pending_financial_links')->assertCanSeeTableRecords([$this->dfe]);
        app(SefazDistributionFinancialLinkService::class)->synchronize($this->dfe);
        Livewire::test(ListSefazDistributionDocuments::class)
            ->resetTableFilters()->filterTable('pending_financial_links')->assertCanNotSeeTableRecords([$this->dfe]);
        Livewire::test(ListSefazDistributionDocuments::class)
            ->resetTableFilters()->filterTable('without_financial')->assertCanNotSeeTableRecords([$this->dfe]);
        $this->assertSame($note->id, $payable->fresh()->fiscal_document_id);
    }

    public function test_generating_financial_after_confirmation_preserves_confirmation_and_links_the_note(): void
    {
        $note = $this->importNote();
        Livewire::test(EditFiscalDocument::class, ['record' => $note->id])
            ->callAction('confirmEntry', data: ['generate_account_payable_now' => false])->assertHasNoActionErrors();
        $this->assertSame('none', $note->fresh()->financial_control_mode);
        $payable = $this->generate();
        $this->assertTrue($note->fresh()->confirmed);
        $this->assertSame('account_payable', $note->fresh()->financial_control_mode);
        $this->assertSame($note->id, $payable->fiscal_document_id);
    }

    public function test_repair_links_a_paid_account_without_recreating_or_changing_its_payment(): void
    {
        $payable = $this->generate();
        $bank = FinancialAccount::create(['company_id' => $this->company->id, 'name' => 'Banco', 'type' => 'bank', 'is_active' => true]);
        $service = app(AccountPayableService::class);
        $payment = $service->registerInstallmentPayment($payable->installments()->sole(), 150.99, '2026-05-10', ['financial_account_id' => $bank->id]);
        $this->assertNotNull($payment, $service->getMessageUser());
        $before = $payment->fresh()->attributesToArray();
        $note = $this->importNote();
        $payable->refresh();
        $this->assertSame($note->id, $payable->fiscal_document_id);
        $this->assertSame('paid', $payable->status->value);
        $this->assertSame($before, $payment->fresh()->attributesToArray());
        $this->assertDatabaseCount('account_payable_installment_payments', 1);
        $this->assertDatabaseCount('cash_movements', 1);
    }

    private function paymentData(): array
    {
        return ['payment_method' => 'boleto', 'payment_condition' => 'a_vista', 'due_date' => '2026-05-10'];
    }

    private function generate(array $data = []): AccountPayable
    {
        return app(GenerateSefazDistributionPayableAction::class)->execute($this->dfe->fresh(), [...$this->paymentData(), ...$data], $this->user->id);
    }

    private function importNote(): FiscalDocument
    {
        return app(SefazDistributionFiscalDocumentImportService::class)->import($this->dfe->fresh(), $this->user->id);
    }

    private function existingAccount(array $data = []): AccountPayable
    {
        return AccountPayable::create([
            'company_id' => $this->company->id, 'supplier_id' => $this->supplier->id, 'sequence_number' => '01',
            'status' => 'pending', 'due_date' => '2026-05-10', 'due_amount' => 150.99, 'paid_amount' => 0,
            'created_by' => $this->user->id, ...$data,
        ]);
    }

    private function writeXml(string $billing = ''): void
    {
        Storage::disk('local')->put('sefaz/workflow.xml', '<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe"><NFe><infNFe Id="NFe35260412345678000199550010000003211000000321">'
            .'<ide><nNF>321</nNF><serie>1</serie><dhEmi>2026-04-19T10:00:00-03:00</dhEmi><tpNF>0</tpNF><finNFe>1</finNFe></ide>'
            .'<emit><CNPJ>12345678000199</CNPJ><xNome>Fornecedor DF-e</xNome></emit>'
            .'<det nItem="1"><prod><cProd>P001</cProd><xProd>Produto DF-e</xProd><uCom>UN</uCom><qCom>1</qCom><vUnCom>140.99</vUnCom><vProd>140.99</vProd><vFrete>10.00</vFrete></prod></det>'
            .'<total><ICMSTot><vProd>140.99</vProd><vFrete>10.00</vFrete><vNF>150.99</vNF></ICMSTot></total>'.$billing
            .'</infNFe></NFe></nfeProc>');
    }
}
