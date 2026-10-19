<?php

namespace Tests\Feature\Commitments;

use App\Enum\Commitment\ImportType;
use App\Enum\Commitment\MatchStatus;
use App\Enum\Commitment\ProcessingStatus;
use App\Enum\Payment\Condition;
use App\Enum\Payment\Method;
use App\Enums\AttachmentType;
use App\Models\AccountReceivable;
use App\Models\AccountReceivableInstallment;
use App\Models\AccountReceivableInstallmentPayment;
use App\Models\CommitmentBilling;
use App\Models\CommitmentImport;
use App\Models\CommitmentImportFile;
use App\Models\CommitmentPaymentLine;
use App\Models\CommitmentReceipt;
use App\Models\CommitmentReceiptPayment;
use App\Models\CommitmentSetting;
use App\Models\Company;
use App\Models\CustomerCommitment;
use App\Models\FinancialAccount;
use App\Models\Invoice;
use App\Models\Partner;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\Attachments\AttachmentService;
use App\Services\Commitments\CommitmentSettingsService;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CommitmentFoundationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Partner $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->customer = Partner::factory()->create();
        $this->company->partners()->attach($this->customer, ['type' => json_encode(['customer']), 'is_active' => true]);
    }

    public function test_commitment_identity_is_unique_per_context_and_money_is_stored_in_cents(): void
    {
        $commitment = $this->commitment();
        $this->assertSame(40000, (int) $commitment->getRawOriginal('amount'));
        $this->assertSame(400.0, $commitment->amount);
        $this->assertSame(MatchStatus::UNMATCHED, $commitment->status);
        $this->assertSame(ProcessingStatus::QUEUED, $commitment->importFile->status);
        $other = Company::factory()->create();
        $other->partners()->attach($this->customer, ['type' => json_encode(['customer'])]);
        $second = $this->commitment($other);
        $this->assertSame(1, CustomerCommitment::forCustomer($this->company->id, $this->customer->id)->count());
        $this->assertNotSame($commitment->id, $second->id);

        $this->expectException(QueryException::class);
        $this->commitment();
    }

    public function test_same_pdf_cannot_be_registered_twice_in_the_same_context(): void
    {
        $file = $this->file();
        $this->expectException(QueryException::class);
        $file->replicate()->save();
    }

    public function test_company_and_customer_cannot_be_changed_after_creation(): void
    {
        $file = $this->file();
        $this->expectException(ValidationException::class);
        $file->update(['company_id' => Company::factory()->create()->id]);
    }

    public function test_cross_company_file_reference_is_rejected(): void
    {
        $other = Company::factory()->create();
        $other->partners()->attach($this->customer, ['type' => json_encode(['customer'])]);
        $commitment = $this->commitment();
        $this->expectException(ValidationException::class);
        $commitment->update(['commitment_import_file_id' => $this->file($other)->id]);
    }

    public function test_a_payment_report_cannot_be_used_as_a_commitment_pdf(): void
    {
        $commitment = $this->commitment();
        $this->expectException(ValidationException::class);
        $commitment->update(['commitment_import_file_id' => $this->file(type: ImportType::PAYMENTS)->id]);
    }

    public function test_service_order_can_only_be_reserved_for_one_commitment(): void
    {
        $order = ServiceOrder::factory()->create(['company_id' => $this->company->id, 'customer_id' => $this->customer->id]);
        $first = CommitmentBilling::create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'customer_commitment_id' => $this->commitment()->id,
            'service_order_id' => $order->id,
            'approval_snapshot' => ['amount' => 40000],
            'approved_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        $first->replicate()->fill(['customer_commitment_id' => $this->commitment(number: '14731')->id])->save();
    }

    public function test_service_order_from_another_customer_cannot_be_linked(): void
    {
        $order = ServiceOrder::factory()->create(['company_id' => $this->company->id]);
        $this->expectException(ValidationException::class);
        CommitmentBilling::create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'customer_commitment_id' => $this->commitment()->id,
            'service_order_id' => $order->id,
            'approval_snapshot' => [],
            'approved_at' => now(),
        ]);
    }

    public function test_settings_are_saved_for_the_explicit_company_and_audited(): void
    {
        $user = $this->authorizedUser();
        $service = app(CommitmentSettingsService::class);
        $data = ['is_enabled' => true, 'parser' => 'chapeco', 'payment_method' => Method::BANK_TRANSFER->value, 'payment_condition' => Condition::DAYS_30->value, 'nfse_footer_message' => 'Mensagem fixa'];
        $settings = $service->save($this->company, $this->customer->id, $user, $data);
        $updated = $service->save($this->company, $this->customer->id, $user, [...$data, 'nfse_footer_message' => 'Mensagem atualizada']);
        $this->assertSame($settings->id, $updated->id);
        $this->assertSame(Method::BANK_TRANSFER, $updated->payment_method);
        $this->assertSame(Condition::DAYS_30, $updated->payment_condition);
        $this->assertDatabaseCount('commitment_settings', 1);
        $this->assertDatabaseHas('audit_entries', ['company_id' => $this->company->id, 'event' => 'commitment.settings_saved', 'actor_user_id' => $user->id]);
    }

    public function test_settings_reject_financial_account_from_another_company(): void
    {
        $user = $this->authorizedUser();
        $account = FinancialAccount::create(['company_id' => Company::factory()->create()->id, 'name' => 'Outra conta', 'type' => 'bank', 'is_active' => true]);
        $this->expectException(ValidationException::class);
        app(CommitmentSettingsService::class)->save($this->company, $this->customer->id, $user, ['is_enabled' => true, 'parser' => 'chapeco', 'financial_account_id' => $account->id]);
    }

    public function test_all_new_models_have_tenant_scoped_financial_policies_and_preserve_history(): void
    {
        $user = $this->authorizedUser();
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->company);
        foreach ([CommitmentSetting::class, CommitmentImport::class, CommitmentImportFile::class, CustomerCommitment::class, CommitmentBilling::class, CommitmentPaymentLine::class, CommitmentReceipt::class, CommitmentReceiptPayment::class] as $model) {
            $record = new $model(['company_id' => $this->company->id]);
            $this->assertTrue(Gate::forUser($user)->allows('view', $record), $model);
            $this->assertFalse(Gate::forUser($user)->allows('delete', $record));
            $record->company_id = $this->company->id + 1;
            $this->assertFalse(Gate::forUser($user)->allows('update', $record));
        }
        $user->companyAccesses()->create(['company_id' => $this->company->id, 'permissions' => ['cash_movements.access'], 'panels' => ['admin' => true], 'navigation' => []]);
        $this->assertFalse(Gate::forUser($user)->allows('viewAny', CustomerCommitment::class));
    }

    public function test_commitment_pdf_is_private_and_requires_financial_permission_for_download(): void
    {
        Storage::fake('local');
        $user = $this->authorizedUser();
        $this->actingAs($user);
        $attachment = app(AttachmentService::class)->upload($this->file(), UploadedFile::fake()->create('empenho.pdf', 10, 'application/pdf'), AttachmentType::COMMITMENT_PDF);
        $this->assertSame('local', $attachment->disk);
        $this->assertTrue(Storage::disk('local')->exists($attachment->path));
        $this->get($attachment->url)->assertOk();
        $user->companyAccesses()->create(['company_id' => $this->company->id, 'permissions' => ['cash_movements.access'], 'panels' => ['admin' => true], 'navigation' => []]);
        $this->get($attachment->url)->assertForbidden();
    }

    public function test_settings_service_rejects_a_user_without_company_membership(): void
    {
        $this->expectException(HttpException::class);
        app(CommitmentSettingsService::class)->save($this->company, $this->customer->id, User::factory()->create(), ['is_enabled' => true, 'parser' => 'chapeco']);
    }

    public function test_settings_service_rejects_an_inactive_customer_link(): void
    {
        $this->company->partners()->updateExistingPivot($this->customer->id, ['is_active' => false]);
        $this->expectException(ValidationException::class);
        app(CommitmentSettingsService::class)->save($this->company, $this->customer->id, $this->authorizedUser(), ['is_enabled' => true, 'parser' => 'chapeco']);
    }

    public function test_receipt_keeps_payment_evidence_when_the_financial_payment_is_removed(): void
    {
        $commitment = $this->commitment();
        $invoice = Invoice::create(['company_id' => $this->company->id, 'customer_id' => $this->customer->id, 'invoice_number' => '1', 'invoice_date' => '2026-07-13', 'status' => 'confirmed']);
        $account = FinancialAccount::create(['company_id' => $this->company->id, 'name' => 'Banco', 'type' => 'bank', 'is_active' => true]);
        $receipt = CommitmentReceipt::create([
            'company_id' => $this->company->id, 'customer_id' => $this->customer->id,
            'customer_commitment_id' => $commitment->id, 'invoice_id' => $invoice->id,
            'financial_account_id' => $account->id, 'idempotency_key' => hash('sha256', 'payment'),
            'payment_date' => '2026-08-27', 'amount' => 400,
        ]);
        $receivable = AccountReceivable::create([
            'company_id' => $this->company->id, 'customer_id' => $this->customer->id,
            'invoice_id' => $invoice->id, 'status' => 'pending', 'due_date' => '2026-08-27',
            'due_amount' => 400, 'paid_amount' => 0, 'description' => 'Empenho',
        ]);
        $installment = AccountReceivableInstallment::create([
            'company_id' => $this->company->id, 'account_receivable_id' => $receivable->id,
            'sequence_number' => '01', 'status' => 'pending', 'due_date' => '2026-08-27',
            'original_amount' => 400, 'received_amount' => 0, 'due_amount' => 400, 'balance_amount' => 400,
        ]);
        $payment = AccountReceivableInstallmentPayment::create([
            'company_id' => $this->company->id, 'account_receivable_installment_id' => $installment->id,
            'payment_date' => '2026-08-27', 'amount' => 400, 'origin' => 'manual',
        ]);
        $link = CommitmentReceiptPayment::create([
            'company_id' => $this->company->id, 'customer_id' => $this->customer->id,
            'commitment_receipt_id' => $receipt->id, 'payment_id' => $payment->id,
            'payment_snapshot' => ['payment_id' => $payment->id, 'amount_cents' => 40000],
        ]);
        $payment->delete();
        $this->assertNull($link->fresh()->payment_id);
        $this->assertSame(40000, $link->fresh()->payment_snapshot['amount_cents']);
        $this->assertSame($receipt->id, $commitment->receipt->id);

        $this->expectException(QueryException::class);
        $receipt->replicate()->fill(['idempotency_key' => hash('sha256', 'other-report')])->save();
    }

    public function test_migration_can_be_rolled_back_and_reapplied_without_removing_existing_business_records(): void
    {
        $migration = require database_path('migrations/2026_10_19_000001_create_commitment_workflow_tables.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('customer_commitments'));
        $this->assertDatabaseHas('companies', ['id' => $this->company->id]);
        $migration->up();
        $this->assertTrue(Schema::hasTable('commitment_receipt_payments'));
    }

    private function authorizedUser(): User
    {
        $user = User::factory()->create();
        $user->companies()->attach($this->company, ['role' => 'member', 'is_active' => true]);

        return $user;
    }

    private function file(?Company $company = null, ImportType $type = ImportType::COMMITMENTS): CommitmentImportFile
    {
        $company ??= $this->company;
        $import = CommitmentImport::create(['company_id' => $company->id, 'customer_id' => $this->customer->id, 'type' => $type]);

        return CommitmentImportFile::create([
            'company_id' => $company->id, 'customer_id' => $this->customer->id,
            'commitment_import_id' => $import->id, 'type' => $type,
            'sha256' => hash('sha256', (string) $import->id), 'original_name' => 'empenho.pdf',
        ])->fresh();
    }

    private function commitment(?Company $company = null, string $number = '14728'): CustomerCommitment
    {
        $file = $this->file($company);

        return CustomerCommitment::create([
            'company_id' => $file->company_id, 'customer_id' => $file->customer_id,
            'commitment_import_file_id' => $file->id, 'number' => $number,
            'fiscal_year' => 2026, 'issued_at' => '2026-06-23', 'patrimony' => '142186',
            'amount' => 400, 'net_amount' => 400, 'original_data' => ['number' => $number],
        ])->fresh();
    }
}
