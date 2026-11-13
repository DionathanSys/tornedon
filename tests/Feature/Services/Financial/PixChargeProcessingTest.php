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
use App\Services\AccountReceivable\AccountReceivableService;
use App\Services\Financial\Banking\BankingOperationService;
use App\Services\Financial\Banking\BankSlipIssuanceService;
use App\Services\Financial\Pix\PixChargeIssuanceService;
use App\Services\Financial\Pix\PixChargePublicLinkService;
use App\Services\Financial\Pix\PixProviderRegistry;
use App\Services\Financial\Pix\Providers\IntegraBancosPixClientInterface;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $this->travelTo(Carbon::parse('2026-10-01 12:00:00'));
        config(['integrabancos-contracts.999' => config('integrabancos-contracts.461')]);

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

    public function test_persists_automatic_pix_issuance_on_new_installments(): void
    {
        $this->customer->companies()->syncWithoutDetaching([$this->company->id => ['is_active' => true, 'type' => '["customer"]']]);
        $service = app(AccountReceivableService::class);
        $receivable = $service->create([
            'customer_id' => $this->customer->id,
            'company_id' => $this->company->id,
            'due_date' => '2026-10-01',
            'due_amount' => 100,
            'payment_method' => PaymentMethod::PIX->value,
            'auto_pix_charge_issuance' => true,
            'installment_count' => 1,
        ], $this->user->id);

        $this->assertNotNull($receivable, $service->getMessage());
        $this->assertTrue((bool) $receivable->fresh()->installments()->sole()->auto_pix_charge_issuance);
    }

    public function test_switching_pix_to_bank_slip_changes_issuance_eligibility(): void
    {
        CompanyEntitlement::create([
            'company_id' => $this->company->id,
            'feature' => 'bank_slip_issuance',
            'enabled' => true,
        ]);
        $service = app(AccountReceivableService::class);
        $updated = $service->updateInstallment($this->installment, ['payment_method' => 'boleto']);
        $this->assertNotNull($updated, $service->getMessageUser());
        $this->assertNull(app(PixChargeIssuanceService::class)->preparePixCharge($updated));
        $this->assertNotNull(app(BankSlipIssuanceService::class)->prepareBankSlip($updated, false));
        $this->assertSame(PaymentMethod::PIX, $updated->accountReceivable->payment_method);
    }

    public function test_active_pix_charge_must_be_resolved_before_switching_method(): void
    {
        $charge = app(PixChargeIssuanceService::class)->preparePixCharge($this->installment);
        $this->assertNotNull($charge);
        $service = app(AccountReceivableService::class);

        $this->assertNull($service->updateInstallment($this->installment, ['payment_method' => 'boleto']));
        $this->assertSame(PaymentMethod::PIX, $this->installment->fresh()->payment_method);

        $charge->update(['status' => PixChargeStatus::CANCELED]);
        $this->assertNotNull($service->updateInstallment($this->installment, ['payment_method' => 'boleto']), $service->getMessageUser());
    }

    public function test_automatic_pix_issuance_respects_installment_method_over_invoice_method(): void
    {
        Queue::fake();
        $invoice = $this->installment->accountReceivable->invoice;
        $invoice->update(['auto_pix_charge_issuance' => true]);
        $this->installment->update(['payment_method' => 'boleto', 'auto_pix_charge_issuance' => true]);

        $this->assertSame(0, app(PixChargeIssuanceService::class)->scheduleForInvoice($invoice->fresh()));
        Queue::assertNotPushed(RegisterPixChargeJob::class);

        $invoice->update(['payment_method' => 'boleto']);
        $this->installment->update(['payment_method' => 'pix']);
        $this->assertSame(1, app(PixChargeIssuanceService::class)->scheduleForInvoice($invoice->fresh()));
        Queue::assertPushed(RegisterPixChargeJob::class, 1);
    }

    public function test_backfills_missing_automatic_flag_when_scheduling_an_invoice(): void
    {
        Queue::fake();

        $invoice = $this->installment->accountReceivable->invoice;
        $invoice->update(['auto_pix_charge_issuance' => true]);
        $this->installment->update(['auto_pix_charge_issuance' => null]);

        $scheduled = app(PixChargeIssuanceService::class)->scheduleForInvoice($invoice->fresh());

        $this->assertSame(1, $scheduled);
        $this->assertTrue((bool) $this->installment->fresh()->auto_pix_charge_issuance);
        Queue::assertPushed(RegisterPixChargeJob::class, 1);
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
                'encodedImage' => 'qr-code-data',
                'payload' => '000201copy-paste',
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

    public function test_registration_uses_current_installment_data_after_queueing(): void
    {
        $service = app(PixChargeIssuanceService::class);
        $charge = $service->preparePixCharge($this->installment);
        $charge->update(['request_snapshot' => ['pagamento' => ['data_vencimento' => '2026-10-01', 'valor' => '100.00']]]);
        $this->installment->update(['due_date' => '2026-11-13', 'balance_amount' => 424.66]);

        $client = Mockery::mock(IntegraBancosPixClientInterface::class);
        $client->shouldReceive('generate')->once()->with(Mockery::on(fn (array $payload): bool => $payload['pagamento']['data_vencimento'] === '2026-11-13'
            && $payload['pagamento']['valor'] === '424.66'
        ))->andReturn(['sucesso' => true, 'status' => ['codigo' => '2']]);
        app()->instance(IntegraBancosPixClientInterface::class, $client);

        (new RegisterPixChargeJob($charge->id))->handle(app(PixProviderRegistry::class));

        $this->assertSame('2026-11-13', $charge->fresh()->due_date->toDateString());
        $this->assertSame(424.66, (float) $charge->fresh()->amount);
    }

    public function test_failed_pix_can_be_requested_again_with_current_installment_data(): void
    {
        $service = app(PixChargeIssuanceService::class);
        $charge = $service->preparePixCharge($this->installment);
        $charge->update([
            'status' => PixChargeStatus::REGISTRATION_FAILED,
            'provider_response_code' => '1', 'request_sent_at' => now(),
            'request_snapshot' => ['pagamento' => ['data_vencimento' => '2026-10-01']],
        ]);
        $this->installment->update(['due_date' => '2026-11-13', 'balance_amount' => 424.66]);

        $retried = $service->preparePixCharge($this->installment);

        $this->assertSame($charge->id, $retried->id);
        $this->assertSame('2026-11-13', $retried->due_date->toDateString());
        $this->assertSame(424.66, (float) $retried->amount);
        $this->assertNull($retried->request_snapshot);
        $this->assertNull($retried->request_sent_at);
        $this->assertTrue(app(BankingOperationService::class)->beginRegistration($retried));
    }

    public function test_pix_request_rejects_a_past_due_date(): void
    {
        $this->installment->update(['due_date' => today()->subDay()]);

        try {
            app(PixChargeIssuanceService::class)->preparePixCharge($this->installment);
            $this->fail('Não deve solicitar PIX com vencimento anterior a hoje.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('due_date', $exception->errors());
        }
        $this->assertDatabaseCount('pix_charges', 0);
    }

    public function test_pix_request_accepts_today_and_future_due_dates(): void
    {
        $service = app(PixChargeIssuanceService::class);
        $this->assertNotNull($service->preparePixCharge($this->installment));
        $this->installment->update(['due_date' => today()->addDay()]);
        $this->assertSame(today()->addDay()->toDateString(), $service->preparePixCharge($this->installment)->due_date->toDateString());
    }

    public function test_queue_does_not_send_a_pix_that_became_overdue(): void
    {
        $charge = app(PixChargeIssuanceService::class)->preparePixCharge($this->installment);
        $this->travel(1)->days();
        $client = Mockery::mock(IntegraBancosPixClientInterface::class);
        $client->shouldNotReceive('generate');
        app()->instance(IntegraBancosPixClientInterface::class, $client);

        (new RegisterPixChargeJob($charge->id))->handle(app(PixProviderRegistry::class));

        $this->assertSame(PixChargeStatus::REGISTRATION_FAILED, $charge->fresh()->status);
        $this->assertSame('LOCAL_VALIDATION', $charge->fresh()->provider_response_code);
        $this->assertNull($charge->fresh()->request_sent_at);
    }

    public function test_preparing_a_failed_pix_with_uncertain_result_preserves_original_request(): void
    {
        $service = app(PixChargeIssuanceService::class);
        $charge = $service->preparePixCharge($this->installment);
        $snapshot = ['pagamento' => ['data_vencimento' => '2026-10-01', 'valor' => '100.00']];
        $charge->update([
            'status' => PixChargeStatus::REGISTRATION_FAILED, 'request_sent_at' => now(),
            'request_snapshot' => $snapshot, 'provider_response_code' => '17',
        ]);
        $this->installment->update(['due_date' => '2026-11-13', 'balance_amount' => 424.66]);

        $retried = $service->preparePixCharge($this->installment);

        $this->assertSame($snapshot, $retried->request_snapshot);
        $this->assertSame('2026-10-01', $retried->due_date->toDateString());
        $this->assertFalse(app(BankingOperationService::class)->beginRegistration($retried));
    }

    public function test_manual_retry_refreshes_a_definitively_rejected_pix(): void
    {
        Queue::fake();
        $this->authorizeRegistrationRetry();
        $charge = app(PixChargeIssuanceService::class)->preparePixCharge($this->installment);
        $charge->update([
            'status' => PixChargeStatus::REGISTRATION_FAILED, 'provider_response_code' => '1',
            'request_sent_at' => now(), 'request_snapshot' => ['pagamento' => ['valor' => '100.00']],
        ]);
        $this->installment->update(['due_date' => '2026-11-13', 'balance_amount' => 424.66]);

        app(BankingOperationService::class)->retryRegistration($charge, $this->company->id, $this->user, 'Vencimento e valor corrigidos antes do reenvio.');

        $this->assertSame('2026-11-13', $charge->fresh()->due_date->toDateString());
        $this->assertSame(424.66, (float) $charge->fresh()->amount);
        $this->assertNull($charge->fresh()->request_snapshot);
        Queue::assertPushed(RegisterPixChargeJob::class);
    }

    public function test_confirmed_not_found_retry_preserves_the_original_payload_before_sending(): void
    {
        $service = app(PixChargeIssuanceService::class);
        $charge = $service->preparePixCharge($this->installment);
        $snapshot = ['pagamento' => ['data_vencimento' => '2026-10-01', 'valor' => '100.00']];
        $charge->update(['provider_response_code' => '12', 'request_snapshot' => $snapshot]);
        $this->installment->update(['due_date' => '2026-11-13', 'balance_amount' => 424.66]);

        $prepared = $service->preparePixCharge($this->installment);
        $this->assertSame($snapshot, $prepared->request_snapshot);
        $this->assertTrue(app(BankingOperationService::class)->beginRegistration($prepared));
        $this->assertSame($snapshot, $charge->fresh()->request_snapshot);
        $this->assertSame(100.0, (float) $charge->fresh()->amount);
    }

    public function test_manual_retry_rejects_an_overdue_installment_without_dispatching(): void
    {
        Queue::fake();
        $this->authorizeRegistrationRetry();
        $charge = app(PixChargeIssuanceService::class)->preparePixCharge($this->installment);
        $charge->update(['status' => PixChargeStatus::REGISTRATION_FAILED, 'provider_response_code' => '1']);
        $this->travel(1)->days();

        try {
            app(BankingOperationService::class)->retryRegistration($charge, $this->company->id, $this->user, 'Nova tentativa de emissão com vencimento passado.');
            $this->fail('Não deve reenviar PIX vencido.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('due_date', $exception->errors());
        }

        Queue::assertNotPushed(RegisterPixChargeJob::class);
        $this->assertSame(PixChargeStatus::REGISTRATION_FAILED, $charge->fresh()->status);
    }

    public function test_uncertain_pix_is_rebuilt_with_current_due_date_after_two_not_found_queries(): void
    {
        Queue::fake();
        $this->authorizeRegistrationRetry();
        $charge = $this->createUncertainPixWithOldDueDate();
        $identification = $charge->provider_identification;
        $client = Mockery::mock(IntegraBancosPixClientInterface::class);
        $client->shouldReceive('query')->twice()->andReturn([
            'sucesso' => false, 'codigo' => 12, 'mensagem' => 'Cobrança não encontrada.',
        ]);
        $client->shouldReceive('generate')->once()->with(Mockery::on(fn (array $payload): bool => $payload['identificacao'] === $identification
            && $payload['pagamento']['data_vencimento'] === '2026-11-13'
            && $payload['pagamento']['valor'] === '424.66'
        ))->andReturn(['sucesso' => true, 'status' => ['codigo' => '2']]);
        app()->instance(IntegraBancosPixClientInterface::class, $client);
        $registry = app(PixProviderRegistry::class);

        (new QueryPixChargeJob($charge->id))->handle($registry);
        try {
            app(BankingOperationService::class)->retryRegistration($charge, $this->company->id, $this->user, 'Uma consulta não deve liberar a recuperação.');
            $this->fail('Não deve reenviar com uma única consulta.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('charge', $exception->errors());
        }
        Queue::assertNotPushed(RegisterPixChargeJob::class);
        $this->assertSame('2026-11-05', $charge->fresh()->request_snapshot['pagamento']['data_vencimento']);

        $this->travel(1)->minutes();
        (new QueryPixChargeJob($charge->id))->handle($registry);
        app(BankingOperationService::class)->retryRegistration($charge, $this->company->id, $this->user, 'Ausência confirmada; usar o vencimento atual da parcela.');

        $this->assertNull($charge->fresh()->request_snapshot);
        $this->assertSame('2026-11-13', $charge->fresh()->due_date->toDateString());
        Queue::assertPushed(RegisterPixChargeJob::class);
        (new RegisterPixChargeJob($charge->id))->handle($registry);
        $this->assertSame(PixChargeStatus::REGISTERED, $charge->fresh()->status);
        $this->assertDatabaseCount('pix_charges', 1);
    }

    public function test_confirmed_absence_does_not_allow_recovery_when_the_installment_is_overdue(): void
    {
        Queue::fake();
        $this->authorizeRegistrationRetry();
        $charge = $this->createUncertainPixWithOldDueDate();
        $client = Mockery::mock(IntegraBancosPixClientInterface::class);
        $client->shouldReceive('query')->twice()->andReturn([
            'sucesso' => false, 'codigo' => 12, 'mensagem' => 'Cobrança não encontrada.',
        ]);
        app()->instance(IntegraBancosPixClientInterface::class, $client);
        $registry = app(PixProviderRegistry::class);
        (new QueryPixChargeJob($charge->id))->handle($registry);
        $this->travel(1)->minutes();
        (new QueryPixChargeJob($charge->id))->handle($registry);
        $this->installment->update(['due_date' => '2026-11-12']);

        try {
            app(BankingOperationService::class)->retryRegistration($charge, $this->company->id, $this->user, 'Ausência confirmada, porém parcela ainda vencida.');
            $this->fail('Não deve recuperar PIX com parcela vencida.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('due_date', $exception->errors());
        }

        $this->assertSame(PixChargeStatus::UNCERTAIN, $charge->fresh()->status);
        $this->assertSame('2026-11-05', $charge->fresh()->request_snapshot['pagamento']['data_vencimento']);
        Queue::assertNotPushed(RegisterPixChargeJob::class);
    }

    private function createUncertainPixWithOldDueDate(): PixCharge
    {
        $charge = app(PixChargeIssuanceService::class)->preparePixCharge($this->installment);
        $this->travelTo(Carbon::parse('2026-11-13 12:00:00'));
        $charge->update([
            'status' => PixChargeStatus::UNCERTAIN, 'operation_status' => 'uncertain',
            'operation_kind' => 'register', 'request_sent_at' => now()->subMinutes(10),
            'due_date' => '2026-11-05',
            'request_snapshot' => [
                'identificacao' => $charge->provider_identification,
                'pagamento' => ['data_vencimento' => '2026-11-05', 'valor' => '100.00'],
            ],
        ]);
        $this->installment->update(['due_date' => '2026-11-13', 'balance_amount' => 424.66]);

        return $charge;
    }

    public function test_uncertain_pix_with_saved_validation_rejection_can_retry_without_queries(): void
    {
        Queue::fake();
        $this->authorizeRegistrationRetry();
        $charge = $this->createPixWithSavedValidationRejection();
        $client = Mockery::mock(IntegraBancosPixClientInterface::class);
        $client->shouldNotReceive('query');
        $client->shouldReceive('generate')->once()->with(Mockery::on(fn (array $payload): bool => $payload['pagamento']['data_vencimento'] === '2026-11-13'
            && $payload['pagamento']['valor'] === '424.66'
        ))->andReturn(['sucesso' => true, 'status' => ['codigo' => '2']]);
        app()->instance(IntegraBancosPixClientInterface::class, $client);

        app(BankingOperationService::class)->retryRegistration($charge, $this->company->id, $this->user, 'Rejeição por vencimento antigo; parcela corrigida para hoje.');

        $this->assertNull($charge->fresh()->request_snapshot);
        $this->assertSame('2026-11-13', $charge->fresh()->due_date->toDateString());
        Queue::assertPushed(RegisterPixChargeJob::class);
        (new RegisterPixChargeJob($charge->id))->handle(app(PixProviderRegistry::class));
        $this->assertSame(PixChargeStatus::REGISTERED, $charge->fresh()->status);
    }

    public function test_reprocessing_a_rejected_registration_does_not_make_its_result_uncertain(): void
    {
        $charge = $this->createPixWithSavedValidationRejection();
        $charge->update(['status' => PixChargeStatus::REGISTRATION_FAILED, 'operation_status' => 'failed']);

        $this->assertFalse(app(BankingOperationService::class)->beginRegistration($charge));
        $this->assertSame(PixChargeStatus::REGISTRATION_FAILED, $charge->fresh()->status);
        $this->assertSame('failed', $charge->fresh()->operation_status);
    }

    #[DataProvider('unconfirmedValidationRejections')]
    public function test_uncertain_pix_cannot_retry_without_unambiguous_rejection(array $attributes): void
    {
        Queue::fake();
        $this->authorizeRegistrationRetry();
        $charge = $this->createPixWithSavedValidationRejection();
        $charge->update($attributes);

        try {
            app(BankingOperationService::class)->retryRegistration($charge, $this->company->id, $this->user, 'Código antigo não deve ignorar evidência de emissão.');
            $this->fail('Não deve liberar reenvio sem rejeição inequívoca.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('charge', $exception->errors());
        }

        $this->assertSame(PixChargeStatus::UNCERTAIN, $charge->fresh()->status);
        Queue::assertNotPushed(RegisterPixChargeJob::class);
    }

    public static function unconfirmedValidationRejections(): array
    {
        return [
            'provider charge id' => [['provider_charge_id' => 'existing-pix']],
            'registered status' => [['provider_status_code' => '2']],
            'registered date' => [['registered_at' => '2026-11-05 12:00:00']],
            'qr code' => [['qr_code' => 'existing-qr']],
            'successful response' => [['provider_payload' => ['sucesso' => true, 'codigo' => 1, 'erros' => ['old error']]]],
            'missing validation response' => [['provider_payload' => null]],
        ];
    }

    private function createPixWithSavedValidationRejection(): PixCharge
    {
        $charge = $this->createUncertainPixWithOldDueDate();
        $charge->update([
            'provider_response_code' => '1',
            'provider_payload' => [
                'sucesso' => false, 'codigo' => 1,
                'mensagem' => 'JSON com erros nos campos, para mais detalhes verifique a propriedade erros',
                'erros' => [[
                    'campo' => 'pagamento.data_vencimento',
                    'erro' => 'Deve ser informado uma data de vencimento futura.',
                    'descricao' => 'Data de vencimento.', 'detalhes' => 'ex. 2026-12-31',
                ]],
            ],
        ]);

        return $charge;
    }

    private function authorizeRegistrationRetry(): void
    {
        $this->user->companies()->attach($this->company, ['role' => 'member', 'is_active' => true]);
        $this->user->companyAccesses()->create([
            'company_id' => $this->company->id, 'permissions' => ['account_receivables.access'],
            'panels' => ['admin' => true], 'navigation' => [],
        ]);
        $this->actingAs($this->user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->company);
    }

    public function test_reads_qr_data_from_a_persisted_provider_payload_when_columns_are_empty(): void
    {
        $charge = PixCharge::create([
            'company_id' => $this->company->id,
            'account_receivable_installment_id' => $this->installment->id,
            'bank_account_connection_id' => BankAccountConnection::query()->firstOrFail()->id,
            'status' => PixChargeStatus::REGISTERED,
            'provider_identification' => 'PX-PAYLOAD',
            'amount' => 100,
            'due_date' => '2026-10-01',
            'provider_payload' => [
                'encodedImage' => 'persisted-qr-image',
                'payload' => 'persisted-copy-paste',
            ],
        ]);

        $this->assertSame('persisted-qr-image', $charge->qr_code);
        $this->assertSame('persisted-copy-paste', $charge->pix_copy_paste);
    }

    public function test_query_registers_one_idempotent_receivable_payment_when_pix_is_paid(): void
    {
        $client = Mockery::mock(IntegraBancosPixClientInterface::class);
        $client->shouldReceive('query')->once()->with(['codigo_banco' => '999', 'identificacao' => 'PX-TEST'])
            ->andReturn([
                'sucesso' => true,
                'identificacao' => 'PX-TEST',
                'valor' => '100.00',
                'data_pagamento' => '2026-10-01 12:00:00',
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

    public function test_public_link_displays_qr_code_and_copy_paste_for_registered_charge(): void
    {
        $charge = $this->createRegisteredCharge();
        $link = app(PixChargePublicLinkService::class)->generate($charge);

        $this->get($link['url'])
            ->assertOk()
            ->assertSee('QR Code para pagamento PIX')
            ->assertSee('000201copy-paste')
            ->assertSee('R$ 100,00');
    }

    public function test_public_link_stops_exposing_payment_data_when_charge_is_paid(): void
    {
        $charge = $this->createRegisteredCharge();
        $link = app(PixChargePublicLinkService::class)->generate($charge);

        $charge->update(['status' => PixChargeStatus::PAID]);

        $this->get($link['url'])
            ->assertStatus(410)
            ->assertSee('Esta cobrança PIX já foi paga.')
            ->assertDontSee('000201copy-paste');
    }

    public function test_public_link_expires_after_sixty_minutes(): void
    {
        $charge = $this->createRegisteredCharge();
        $link = app(PixChargePublicLinkService::class)->generate($charge);

        $this->travel(61)->minutes();

        $this->get($link['url'])->assertForbidden();

        $this->travelBack();
    }

    public function test_paid_pix_after_manual_receipt_requires_review_without_duplicate_cash(): void
    {
        $charge = $this->createRegisteredCharge();
        $payment = app(AccountReceivableService::class)->registerInstallmentPayment($this->installment, 100, '2026-10-01', [
            'financial_account_id' => $this->financialAccount->id,
        ]);
        $this->assertNotNull($payment);
        $this->mockPaidQuery();

        (new QueryPixChargeJob($charge->id))->handle(app(PixProviderRegistry::class));

        $this->assertSame(PixChargeStatus::NEEDS_REVIEW, $charge->fresh()->status);
        $this->assertDatabaseCount('account_receivable_installment_payments', 1);
        $this->assertDatabaseCount('cash_movements', 1);
    }

    public function test_paid_pix_confirms_the_linked_automatic_receipt_without_new_cash(): void
    {
        $charge = $this->createRegisteredCharge();
        $this->installment->accountReceivable->update([
            'auto_register_receipt_on_due_date' => true,
            'auto_receipt_financial_account_id' => $this->financialAccount->id,
        ]);
        $this->artisan('account-receivables:process-auto-receipts', ['--date' => '2026-10-01'])->assertSuccessful();
        $payment = $this->installment->payments()->sole();
        $this->assertSame('due_date', $payment->origin);
        $this->assertSame($charge->id, $payment->pix_charge_id);
        $this->assertNull($payment->bank_confirmed_at);
        $this->mockPaidQuery();

        (new QueryPixChargeJob($charge->id))->handle(app(PixProviderRegistry::class));

        $this->assertSame(PixChargeStatus::PAID, $charge->fresh()->status);
        $this->assertNotNull($payment->fresh()->bank_confirmed_at);
        $this->assertDatabaseCount('account_receivable_installment_payments', 1);
        $this->assertDatabaseCount('cash_movements', 1);
    }

    public function test_pix_liquidation_uses_the_charge_connection_instead_of_the_changed_installment_account(): void
    {
        $charge = $this->createRegisteredCharge();
        $other = FinancialAccount::create([
            'company_id' => $this->company->id, 'name' => 'Outra conta', 'type' => FinancialAccountType::BANK,
            'opening_balance' => 0, 'is_active' => true,
        ]);
        $this->installment->update(['financial_account_id' => $other->id]);
        $charge->connection->update(['financial_account_id' => $other->id]);
        $this->mockPaidQuery();

        (new QueryPixChargeJob($charge->id))->handle(app(PixProviderRegistry::class));

        $this->assertSame($this->financialAccount->id, $this->installment->payments()->sole()->financial_account_id);
        $this->assertDatabaseHas('cash_movements', ['financial_account_id' => $this->financialAccount->id]);
    }

    private function mockPaidQuery(): void
    {
        $client = Mockery::mock(IntegraBancosPixClientInterface::class);
        $client->shouldReceive('query')->once()->andReturn([
            'sucesso' => true, 'valor' => '100.00', 'data_pagamento' => '2026-10-01 12:00:00',
            'status' => ['codigo' => '3', 'mensagem' => 'Pago/Liquidado'],
        ]);
        app()->instance(IntegraBancosPixClientInterface::class, $client);
    }

    private function createRegisteredCharge(): PixCharge
    {
        return PixCharge::create([
            'company_id' => $this->company->id,
            'account_receivable_installment_id' => $this->installment->id,
            'bank_account_connection_id' => BankAccountConnection::query()->firstOrFail()->id,
            'financial_account_id' => $this->financialAccount->id,
            'status' => PixChargeStatus::REGISTERED,
            'provider_identification' => 'PX-PUBLIC-LINK',
            'amount' => 100,
            'due_date' => '2026-10-01',
            'qr_code' => 'public-qr-image',
            'pix_copy_paste' => '000201copy-paste',
        ]);
    }
}
