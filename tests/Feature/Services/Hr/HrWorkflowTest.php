<?php

namespace Tests\Feature\Services\Hr;

use App\Enum\Hr\PaymentNature;
use App\Filament\Clusters\Hr\Resources\HrEntries\Pages\ManageHrEntries;
use App\Filament\Clusters\Hr\Resources\HrPeople\Pages\ManageHrPeople;
use App\Filament\Clusters\Hr\Resources\HrReceipts\Pages\ManageHrReceipts;
use App\Filament\Pages\CompanyTeam;
use App\Models\AccountPayable;
use App\Models\Company;
use App\Models\CompanyPartner;
use App\Models\CompanyRole;
use App\Models\FinancialAccount;
use App\Models\HrEntry;
use App\Models\HrPerson;
use App\Models\HrReceipt;
use App\Models\Partner;
use App\Models\User;
use App\Services\Access\CompanyTeamService;
use App\Services\Access\PermissionCatalog;
use App\Services\AccountPayable\AccountPayableService;
use App\Services\Hr\HrAccess;
use App\Services\Hr\HrService;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class HrWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Company $company;

    private HrService $hr;

    private HrPerson $person;

    private FinancialAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->company = Company::factory()->create(['created_by' => $this->owner->id]);
        $this->owner->companies()->attach($this->company, ['role' => 'owner', 'is_active' => true]);
        $this->actingAs($this->owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->company);
        $this->hr = app(HrService::class);
        $partner = Partner::factory()->create(['created_by' => $this->owner->id]);
        $link = CompanyPartner::create(['company_id' => $this->company->id, 'partner_id' => $partner->id, 'type' => ['employee'], 'is_active' => true]);
        $this->person = $this->hr->savePerson($this->owner, $this->company, ['company_partner_id' => $link->id, 'roles' => ['employee', 'shareholder'], 'is_active' => true, 'base_amount' => 3000]);
        $this->account = FinancialAccount::create(['company_id' => $this->company->id, 'name' => 'Banco RH', 'type' => 'bank', 'opening_balance' => 10000, 'is_active' => true, 'created_by' => $this->owner->id]);
    }

    private function draft(array $overrides = []): HrEntry
    {
        return $this->hr->saveEntry($this->owner, $this->company, [
            'hr_person_id' => $this->person->id, 'competence_date' => '2026-09-15', 'due_date' => '2026-10-05', 'nature' => 'salary',
            'items' => [['description' => 'Salário-base', 'type' => 'earning', 'amount' => '3000.00'], ['description' => 'INSS informado', 'type' => 'deduction', 'amount' => '270.00']],
            ...$overrides,
        ]);
    }

    private function confirmed(array $overrides = []): HrEntry
    {
        return $this->hr->confirm($this->owner, $this->company, $this->draft($overrides));
    }

    private function pay(HrEntry $entry, float $amount, array $extra = [])
    {
        $finance = app(AccountPayableService::class);
        $payment = $finance->registerInstallmentPayment($entry->accountPayable->installments()->firstOrFail(), $amount, '2026-10-05', $extra + ['financial_account_id' => $this->account->id]);
        $this->assertNotNull($payment, $finance->getMessage().' '.json_encode($finance->getErrors()));

        return $payment;
    }

    private function userWith(array $permissions): array
    {
        $teams = app(CompanyTeamService::class);
        $role = $teams->saveRole($this->owner, $this->company, ['name' => 'RH teste '.User::count(), 'permissions' => $permissions, 'panels' => ['admin']]);
        $user = User::factory()->create();
        $teams->assign($this->owner, $this->company, $user, [$role->id]);

        return [$user, $role];
    }

    public function test_confirmation_is_idempotent_and_preserves_competence_and_cash_integration(): void
    {
        $entry = $this->confirmed();
        $again = $this->hr->confirm($this->owner, $this->company, $entry);
        $this->assertSame($entry->account_payable_id, $again->account_payable_id);
        $this->assertSame(1, AccountPayable::count());
        $this->assertEquals(2730, $entry->net_amount);
        $this->assertSame('2026-09-01', $entry->accountPayable->installments->first()->competence_date->toDateString());
        $account = FinancialAccount::create(['company_id' => $this->company->id, 'name' => 'Banco', 'type' => 'bank', 'opening_balance' => 10000, 'is_active' => true, 'created_by' => $this->owner->id]);
        $payment = $this->pay($entry, 2730, ['financial_account_id' => $account->id]);
        $this->assertDatabaseHas('cash_movements', ['origin_id' => $payment->id, 'company_id' => $this->company->id, 'competence_date' => '2026-09-01 00:00:00']);
    }

    public function test_partial_receipts_preserve_identity_and_are_invalidated_when_payment_is_reversed(): void
    {
        $entry = $this->confirmed();
        $payment = $this->pay($entry, 1000);
        $receipt = $this->hr->issueReceipt($this->owner, $this->company, $entry, $payment->id);
        $this->assertSame($receipt->id, $this->hr->issueReceipt($this->owner, $this->company, $entry, $payment->id)->id);
        $this->assertEquals(1000, $receipt->snapshot['payment_amount']);
        $this->assertEquals(1730, $receipt->snapshot['balance_amount']);
        $originalName = $receipt->snapshot['person_name'];
        $this->person->companyPartner->partner->update(['name' => 'Nome alterado']);
        $this->assertSame($originalName, $receipt->fresh()->snapshot['person_name']);
        $this->assertTrue($this->hr->receiptIsValid($receipt));
        $this->assertTrue(app(AccountPayableService::class)->deleteInstallmentPayment($payment));
        $this->assertFalse($this->hr->receiptIsValid($receipt->fresh()));
        $this->get(URL::temporarySignedRoute('hr.receipt', now()->addMinutes(10), ['company' => $this->company->id, 'receipt' => $receipt->id]))->assertStatus(409);
    }

    public function test_receipt_cannot_be_issued_before_payment_or_using_another_entry_payment(): void
    {
        $first = $this->confirmed();
        $second = $this->confirmed();
        $payment = $this->pay($second, 100);
        $this->expectException(ValidationException::class);
        $this->hr->issueReceipt($this->owner, $this->company, $first, $payment->id);
    }

    public function test_advances_are_compensated_once_and_reversal_requires_releasing_allocations(): void
    {
        $advance = $this->confirmed(['nature' => 'advance', 'items' => [['description' => 'Adiantamento', 'type' => 'earning', 'amount' => 500]]]);
        $payment = $this->pay($advance, 500);
        $deductions = [['entry_id' => $advance->id, 'amount' => 500]];
        $salary = $this->confirmed(['advance_deductions' => $deductions]);
        $this->assertEquals(2230, $salary->net_amount);
        $this->assertEquals(2230, $salary->accountPayable->due_amount);
        $finance = app(AccountPayableService::class);
        $this->assertFalse($finance->deleteInstallmentPayment($payment));
        $this->assertDatabaseHas('account_payable_installment_payments', ['id' => $payment->id]);
        try {
            $this->draft(['advance_deductions' => $deductions]);
            $this->fail('Duplicated advance consumption was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('advance_deductions', $e->errors());
        }
        $this->hr->cancel($this->owner, $this->company, $salary);
        $this->assertDatabaseMissing('hr_advance_allocations', ['hr_entry_id' => $salary->id]);
        $this->assertTrue($finance->deleteInstallmentPayment($payment));
    }

    public function test_confirmation_rolls_back_when_finance_creation_fails(): void
    {
        $entry = $this->draft();
        $this->mock(AccountPayableService::class, function ($mock): void {
            $mock->shouldReceive('create')->once()->andReturnNull();
            $mock->shouldReceive('getErrors')->andReturn(['finance' => ['Falha simulada']]);
        });
        try {
            $this->hr->confirm($this->owner, $this->company, $entry);
            $this->fail('Expected finance failure.');
        } catch (ValidationException $e) {
            $this->assertSame('draft', $entry->fresh()->status);
            $this->assertNull($entry->fresh()->account_payable_id);
            $this->assertSame(0, AccountPayable::count());
        }
    }

    public function test_all_four_payment_natures_can_be_created_for_matching_roles(): void
    {
        foreach (array_keys(PaymentNature::options()) as $nature) {
            $this->assertSame('confirmed', $this->confirmed(['nature' => $nature])->status);
        }
    }

    public function test_negative_net_amount_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->draft(['items' => [['description' => 'Base', 'type' => 'earning', 'amount' => 10], ['description' => 'Desconto', 'type' => 'deduction', 'amount' => 11]]]);
    }

    public function test_company_scoping_is_enforced_in_services_and_signed_downloads(): void
    {
        $entry = $this->confirmed();
        $payment = $this->pay($entry, 100);
        $receipt = $this->hr->issueReceipt($this->owner, $this->company, $entry, $payment->id);
        $outsider = User::factory()->create();
        $this->actingAs($outsider);
        $this->get(URL::temporarySignedRoute('hr.receipt', now()->addMinutes(10), ['company' => $this->company->id, 'receipt' => $receipt->id]))->assertForbidden();
        $this->expectException(AuthorizationException::class);
        $this->hr->confirm($outsider, $this->company, $entry);
    }

    public function test_financial_and_consultation_templates_do_not_grant_hr_and_existing_roles_are_preserved(): void
    {
        $templates = PermissionCatalog::templates();
        foreach (['Financeiro', 'Consulta', 'BPO responsável', 'Auxiliar BPO'] as $name) {
            $this->assertSame([], array_filter(array_keys($templates[$name]), fn ($key) => str_starts_with($key, 'hr_')));
        }
        [$user, $role] = $this->userWith($templates['Financeiro']);
        $this->assertFalse(app(HrAccess::class)->allows($user, $this->company, 'hr_entries.view'));
        $teams = app(CompanyTeamService::class);
        $teams->installTemplates($this->owner, $this->company);
        $this->assertSame($role->permissions, $role->fresh()->permissions);
        $this->assertDatabaseHas('company_roles', ['company_id' => $this->company->id, 'name' => 'Gestor de RH']);
        $this->assertSame(['admin'], CompanyRole::where('company_id', $this->company->id)->where('name', 'Gestor de RH')->first()->panels);
    }

    public function test_hr_manager_can_create_confirm_and_issue_but_not_settle_without_financial_grant(): void
    {
        [$manager] = $this->userWith(PermissionCatalog::templates()['Gestor de RH']);
        $this->actingAs($manager);
        $entry = $this->hr->saveEntry($manager, $this->company, ['hr_person_id' => $this->person->id, 'competence_date' => '2026-09-01', 'due_date' => '2026-10-05', 'nature' => 'salary', 'items' => [['description' => 'Salário', 'type' => 'earning', 'amount' => 100]]]);
        $entry = $this->hr->confirm($manager, $this->company, $entry);
        $this->assertNotNull($entry->account_payable_id);
        $finance = app(AccountPayableService::class);
        $this->assertNull($finance->registerInstallmentPayment($entry->accountPayable->installments->first(), 100, '2026-10-05'));
    }

    public function test_own_scope_and_revocation_apply_to_already_mounted_actions(): void
    {
        $entry = $this->draft();
        [$user, $role] = $this->userWith(['hr_entries.view' => 'own', 'hr_entries.create' => 'company', 'hr_entries.update' => 'own', 'hr_people.view' => 'company']);
        $this->actingAs($user);
        $this->assertSame(0, HrEntry::count());
        $owned = $this->hr->saveEntry($user, $this->company, ['hr_person_id' => $this->person->id, 'competence_date' => '2026-09-01', 'due_date' => '2026-10-05', 'nature' => 'salary', 'items' => [['description' => 'Base', 'type' => 'earning', 'amount' => 100]]]);
        Livewire::test(ManageHrEntries::class)->assertCanSeeTableRecords([$owned])->assertCanNotSeeTableRecords([$entry]);
        $role->update(['permissions' => ['hr_entries.view' => 'own', 'hr_people.view' => 'company']]);
        $this->expectException(AuthorizationException::class);
        $this->hr->saveEntry($user, $this->company, $owned->attributesToArray(), $owned);
    }

    public function test_hr_screens_and_receipt_pdf_render(): void
    {
        $entry = $this->confirmed();
        $payment = $this->pay($entry, 100);
        $receipt = $this->hr->issueReceipt($this->owner, $this->company, $entry, $payment->id);
        Livewire::test(ManageHrPeople::class)->assertSuccessful()->assertCanSeeTableRecords([$this->person]);
        Livewire::test(ManageHrEntries::class)->assertSuccessful()->assertCanSeeTableRecords([$entry]);
        Livewire::test(ManageHrReceipts::class)->assertSuccessful()->assertCanSeeTableRecords([$receipt]);
        $this->get(URL::temporarySignedRoute('hr.receipt', now()->addMinutes(10), ['company' => $this->company->id, 'receipt' => $receipt->id]))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get(URL::temporarySignedRoute('hr.statement', now()->addMinutes(10), ['company' => $this->company->id, 'entry' => $entry->id]))->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_forms_create_and_edit_entries_through_the_domain_service(): void
    {
        $this->beforeApplicationDestroyed(Repeater::fake());
        Livewire::test(ManageHrEntries::class)->callAction('create', data: [
            'hr_person_id' => $this->person->id, 'competence_date' => '2026-09-15', 'due_date' => '2026-10-05', 'nature' => 'salary',
            'items' => [['description' => 'Base', 'type' => 'earning', 'amount' => 100]],
        ])->assertHasNoActionErrors();
        $entry = HrEntry::firstOrFail();
        $this->assertEquals(100, $entry->net_amount);
        Livewire::test(ManageHrEntries::class)->callTableAction('edit', $entry, data: [
            'hr_person_id' => $this->person->id, 'competence_date' => '2026-09-01', 'due_date' => '2026-10-05', 'nature' => 'salary',
            'items' => [['description' => 'Base corrigida', 'type' => 'earning', 'amount' => 200]],
        ])->assertHasNoTableActionErrors();
        $this->assertEquals(200, $entry->fresh()->net_amount);
    }

    public function test_pending_advance_cannot_be_used_and_confirmation_rechecks_draft_allocations(): void
    {
        $advance = $this->confirmed(['nature' => 'advance', 'items' => [['description' => 'Adiantamento', 'type' => 'earning', 'amount' => 500]]]);
        $deductions = [['entry_id' => $advance->id, 'amount' => 500]];
        try {
            $this->draft(['advance_deductions' => $deductions]);
            $this->fail('Unpaid advance was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('advance_deductions', $e->errors());
        }
        $this->pay($advance, 500);
        $first = $this->draft(['advance_deductions' => $deductions]);
        $second = $this->draft(['advance_deductions' => $deductions]);
        $this->hr->confirm($this->owner, $this->company, $first);
        try {
            $this->hr->confirm($this->owner, $this->company, $second);
            $this->fail('Already allocated advance was accepted.');
        } catch (ValidationException $e) {
            $this->assertSame('draft', $second->fresh()->status);
            $this->assertNull($second->fresh()->account_payable_id);
        }
    }

    public function test_editing_payment_invalidates_old_receipt_and_new_emission_uses_updated_payment(): void
    {
        $entry = $this->confirmed();
        $payment = $this->pay($entry, 100);
        $receipt = $this->hr->issueReceipt($this->owner, $this->company, $entry, $payment->id);
        $finance = app(AccountPayableService::class);
        $updated = $finance->updateInstallmentPayment($payment, ['amount' => 200]);
        $this->assertNotNull($updated, $finance->getMessage());
        $this->assertFalse($this->hr->receiptIsValid($receipt->fresh()));
        $new = $this->hr->issueReceipt($this->owner, $this->company, $entry, $payment->id);
        $this->assertNotSame($receipt->id, $new->id);
        $this->assertEquals(200, $new->snapshot['payment_amount']);
        $this->assertEquals(100, $receipt->fresh()->snapshot['payment_amount']);
    }

    public function test_confirmed_entry_cannot_be_reopened_and_finance_cannot_change_its_principal(): void
    {
        $entry = $this->confirmed();
        $finance = app(AccountPayableService::class);
        $this->assertNull($finance->updateInstallment($entry->accountPayable->installments->first(), ['due_amount' => 1, 'original_amount' => 1]));
        $this->assertEquals(2730, $entry->accountPayable->installments->first()->fresh()->original_amount);
        $this->expectException(ValidationException::class);
        $entry->update(['status' => 'draft']);
    }

    public function test_finance_cannot_add_installments_to_confirmed_hr_obligation(): void
    {
        $entry = $this->confirmed();
        $installment = $entry->accountPayable->installments->first();
        $this->expectException(ValidationException::class);
        $entry->accountPayable->installments()->create([
            'company_id' => $this->company->id, 'sequence_number' => '02', 'status' => 'pending',
            'due_date' => $installment->due_date, 'original_amount' => 100, 'due_amount' => 100, 'balance_amount' => 100,
        ]);
    }

    public function test_legacy_non_owner_is_not_implicitly_authorized_for_hr(): void
    {
        $entry = $this->draft();
        $user = User::factory()->create();
        $user->companies()->attach($this->company, ['role' => 'member', 'is_active' => true]);
        $this->actingAs($user);
        $this->assertFalse(app(HrAccess::class)->allows($user, $this->company, 'hr_entries.view'));
        $this->assertSame(0, HrEntry::count());
        Livewire::test(ManageHrEntries::class)->assertForbidden();
    }

    public function test_foreign_company_person_and_classification_are_rejected(): void
    {
        $other = Company::factory()->create();
        $foreign = DB::table('financial_categories')->insertGetId(['company_id' => $other->id, 'name' => 'Outra empresa', 'is_active' => true]);
        $this->expectException(ValidationException::class);
        $this->draft(['financial_category_id' => $foreign]);
    }

    public function test_csv_export_respects_record_scope_and_requires_export_permission(): void
    {
        $entry = $this->draft();
        $name = $this->person->companyPartner->partner->name;
        [$user] = $this->userWith(['hr_entries.view' => 'company', 'hr_entries.export' => 'own']);
        $this->actingAs($user);
        $url = URL::temporarySignedRoute('hr.export', now()->addMinutes(10), ['company' => $this->company->id, 'module' => 'entries']);
        $response = $this->get($url)->assertOk();
        $this->assertStringNotContainsString($name, $response->streamedContent());
        [$viewer] = $this->userWith(['hr_entries.view' => 'company']);
        $this->actingAs($viewer)->get($url)->assertForbidden();
    }

    public function test_existing_role_can_receive_hr_template_without_overwriting_custom_scopes(): void
    {
        [, $role] = $this->userWith(['quotes.view' => 'own', 'hr_entries.view' => 'own']);
        Livewire::test(CompanyTeam::class)
            ->mountAction('role', arguments: ['role_id' => $role->id])
            ->set('mountedActions.0.data.hr_template', 'Gestor de RH')
            ->callMountedAction()->assertHasNoActionErrors();
        $permissions = $role->fresh()->permissions;
        $this->assertSame('own', $permissions['quotes.view']);
        $this->assertSame('own', $permissions['hr_entries.view']);
        $this->assertSame('company', $permissions['hr_entries.approve']);
        $this->assertSame('company', $permissions['hr_receipts.issue']);
    }

    public function test_same_person_has_independent_hr_links_in_two_companies(): void
    {
        $other = Company::factory()->create(['created_by' => $this->owner->id]);
        $this->owner->companies()->attach($other, ['role' => 'owner', 'is_active' => true]);
        $link = CompanyPartner::create(['company_id' => $other->id, 'partner_id' => $this->person->companyPartner->partner_id, 'type' => ['shareholder'], 'is_active' => true]);
        $otherPerson = $this->hr->savePerson($this->owner, $other, ['company_partner_id' => $link->id, 'roles' => ['shareholder'], 'base_amount' => 5000, 'is_active' => true]);
        $this->assertEquals(3000, $this->person->fresh()->base_amount);
        $this->assertEquals(5000, $otherPerson->base_amount);
        Livewire::test(ManageHrPeople::class)->assertCanSeeTableRecords([$this->person])->assertCanNotSeeTableRecords([$otherPerson]);
        $this->expectException(ValidationException::class);
        $this->draft(['hr_person_id' => $otherPerson->id]);
    }

    public function test_receipt_visibility_requires_both_receipt_permission_and_entry_scope_and_is_revocable(): void
    {
        $entry = $this->confirmed();
        $payment = $this->pay($entry, 100);
        $receipt = $this->hr->issueReceipt($this->owner, $this->company, $entry, $payment->id);
        [$user, $role] = $this->userWith(['hr_entries.view' => 'own', 'hr_receipts.view' => 'company', 'hr_receipts.export' => 'company']);
        $this->actingAs($user);
        $this->assertSame(0, HrReceipt::count());
        $url = URL::temporarySignedRoute('hr.receipt', now()->addMinutes(10), ['company' => $this->company->id, 'receipt' => $receipt->id]);
        $this->get($url)->assertForbidden();
        $role->update(['permissions' => ['hr_entries.view' => 'company', 'hr_receipts.view' => 'company', 'hr_receipts.export' => 'company']]);
        $this->get($url)->assertOk();
        $role->update(['permissions' => ['hr_entries.view' => 'company', 'hr_receipts.view' => 'company']]);
        $this->get($url)->assertForbidden();
    }
}
