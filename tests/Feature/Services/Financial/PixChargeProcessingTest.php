<?php

namespace Tests\Feature\Services\Financial;

use App\Enum\AccountReceivable\Status as AccountReceivableStatus;
use App\Enum\Financial\FinancialAccountType;
use App\Enum\Financial\PixChargeStatus;
use App\Enum\Invoice\Status as InvoiceStatus;
use App\Enum\Payment\Method as PaymentMethod;
use App\Jobs\QueryPixChargeJob;
use App\Jobs\RegisterPixChargeJob;
use App\Models\AccountReceivable;
use App\Models\AccountReceivableInstallment;
use App\Models\Bank;
use App\Models\BankAccountConnection;
use App\Models\BillingProvider;
use App\Models\Company;
use App\Models\CompanyEntitlement;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\Invoice;
use App\Models\Partner;
use App\Models\PixCharge;
use App\Models\User;
use App\Services\Financial\Pix\PixChargeIssuanceService;
use App\Services\Financial\Pix\PixProviderRegistry;
use App\Services\Financial\Pix\Providers\IntegraBancosPixClientInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class PixChargeProcessingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private Partner $customer;

    private FinancialAccount $financialAccount;

    private AccountReceivableInstallment $installment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->company = Company::create([
            'name' => 'Empresa PIX',
            'document_number' => '12345678000188',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $this->user->id,
        ]);
        $this->customer = Partner::create([
            'name' => 'Cliente PIX',
            'document_type' => 'CPF',
            'document_number' => '12345678901',
            'created_by' => $this->user->id,
        ]);
        $this->financialAccount = FinancialAccount::create([
            'company_id' => $this->company->id,
            'name' => 'Conta PIX de Teste',
            'type' => FinancialAccountType::BANK->value,
            'opening_balance' => 0,
            'is_active' => true,
            'created_by' => $this->user->id,
        ]);

        $parentCategory = FinancialCategory::create([
            'company_id' => $this->company->id,
            'name' => 'Receitas',
            'allow_receivable' => false,
            'allow_cash_movement' => false,
            'is_active' => true,
            'created_by' => $this->user->id,
        ]);
        $category = FinancialCategory::create([
            'company_id' => $this->company->id,
            'parent_id' => $parentCategory->id,
            'name' => 'PIX',
            'allow_receivable' => true,
            'allow_cash_movement' => true,
            'is_active' => true,
            'created_by' => $this->user->id,
        ]);

        $invoice = Invoice::create([
            'customer_id' => $this->customer->id,
            'company_id' => $this->company->id,
            'invoice_number' => '000321',
            'invoice_date' => '2026-09-01',
            'payment_method' => PaymentMethod::PIX->value,
            'status' => InvoiceStatus::CONFIRMED->value,
            'pending' => false,
            'confirmed' => true,
            'created_by' => $this->user->id,
        ]);
        $receivable = AccountReceivable::create([
            'customer_id' => $this->customer->id,
            'company_id' => $this->company->id,
            'invoice_id' => $invoice->id,
            'status' => AccountReceivableStatus::PENDING->value,
            'due_date' => '2026-10-01',
            'due_amount' => 100,
            'paid_amount' => 0,
            'paid' => false,
            'payment_method' => PaymentMethod::PIX->value,
        ]);
        $this->installment = AccountReceivableInstallment::create([
            'account_receivable_id' => $receivable->id,
            'company_id' => $this->company->id,
            'sequence_number' => '01',
            'status' => AccountReceivableStatus::PENDING->value,
            'due_date' => '2026-10-01',
            'original_amount' => 100,
            'due_amount' => 100,
            'received_amount' => 0,
            'balance_amount' => 100,
            'financial_account_id' => $this->financialAccount->id,
            'financial_category_id' => $category->id,
        ]);

        $bank = Bank::create(['code' => '999', 'name' => 'Banco PIX de Teste']);
        $provider = BillingProvider::query()->where('key', 'integrabancos')->firstOrFail();
        $provider->update(['capabilities' => ['bank_slip' => true, 'pix' => true]]);
        BankAccountConnection::create([
            'company_id' => $this->company->id,
            'financial_account_id' => $this->financialAccount->id,
            'bank_id' => $bank->id,
            'billing_provider_id' => $provider->id,
            'credentials' => ['access_token' => 'test-token', 'x_api_key' => 'test-key'],
            'settings' => [],
            'status' => 'active',
        ]);

        CompanyEntitlement::create([
            'company_id' => $this->company->id,
            'feature' => 'pix_charge_issuance',
            'enabled' => true,
        ]);
    }

    public function test_registers_pix_charge_with_provider_payload_and_qr_data(): void
    {
        $client = Mockery::mock(IntegraBancosPixClientInterface::class);
        $client->shouldReceive('generate')
            ->once()
            ->with(Mockery::on(function (array $payload): bool {
                return $payload['codigo_banco'] === '999'
                    && $payload['pagamento']['valor'] === '100.00'
                    && $payload['pagador']['cpf'] === '12345678901';
            }))
            ->andReturn([
                'sucesso' => true,
                'identificacao' => 'PX-registered',
                'id' => 'pix-provider-1',
                'qrcode' => 'qr-code-data',
                'pix_copia_cola' => '000201copy-paste',
                'status' => ['codigo' => '2', 'mensagem' => 'Gerado'],
            ]);
        app()->instance(IntegraBancosPixClientInterface::class, $client);

        $charge = app(PixChargeIssuanceService::class)->preparePixCharge($this->installment);
        $this->assertNotNull($charge);

        (new RegisterPixChargeJob($charge->id))->handle(app(PixProviderRegistry::class));

        $charge = $charge->fresh();
        $this->assertSame(PixChargeStatus::REGISTERED, $charge->status);
        $this->assertSame('pix-provider-1', $charge->provider_charge_id);
        $this->assertSame('qr-code-data', $charge->qr_code);
        $this->assertSame('000201copy-paste', $charge->pix_copy_paste);
    }

    public function test_query_registers_one_idempotent_receivable_payment_when_pix_is_paid(): void
    {
        $client = Mockery::mock(IntegraBancosPixClientInterface::class);
        $client->shouldReceive('query')->once()->with(Mockery::on(fn (array $payload): bool => $payload['identificacao'] === 'PX-TEST'))
            ->andReturn([
                'sucesso' => true,
                'identificacao' => 'PX-TEST',
                'valor' => '100.00',
                'status' => ['codigo' => '3', 'mensagem' => 'Pago/Liquidado'],
            ]);
        app()->instance(IntegraBancosPixClientInterface::class, $client);

        $charge = PixCharge::create([
            'company_id' => $this->company->id,
            'account_receivable_installment_id' => $this->installment->id,
            'bank_account_connection_id' => BankAccountConnection::query()->firstOrFail()->id,
            'status' => PixChargeStatus::REGISTERED,
            'provider_identification' => 'PX-TEST',
            'provider_charge_id' => 'pix-provider-1',
            'amount' => 100,
            'due_date' => '2026-10-01',
        ]);

        (new QueryPixChargeJob($charge->id))->handle(app(PixProviderRegistry::class));
        (new QueryPixChargeJob($charge->id))->handle(app(PixProviderRegistry::class));

        $this->assertSame(PixChargeStatus::PAID, $charge->fresh()->status);
        $this->assertDatabaseCount('account_receivable_installment_payments', 1);
        $this->assertDatabaseHas('account_receivable_installment_payments', [
            'pix_charge_id' => $charge->id,
            'amount' => 10000,
        ]);
        $this->assertSame(0.0, (float) $this->installment->fresh()->balance_amount);
    }
}
