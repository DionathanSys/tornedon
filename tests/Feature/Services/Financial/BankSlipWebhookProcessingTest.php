<?php

namespace Tests\Feature\Services\Financial;

use App\Enum\AccountReceivable\Status as AccountReceivableStatus;
use App\Enum\Financial\BankSlipStatus;
use App\Enum\Financial\FinancialAccountType;
use App\Enum\Invoice\Status as InvoiceStatus;
use App\Enum\Payment\Method as PaymentMethod;
use App\Jobs\ProcessBankSlipWebhookJob;
use App\Jobs\QueryBankSlipJob;
use App\Jobs\RegisterBankSlipJob;
use App\Jobs\ScheduleBankSlipCancellationJob;
use App\Jobs\ScheduleBankSlipIssuanceJob;
use App\Models\AccountReceivable;
use App\Models\AccountReceivableInstallment;
use App\Models\Bank;
use App\Models\BankAccountConnection;
use App\Models\BankSlip;
use App\Models\BankSlipEvent;
use App\Models\BillingProvider;
use App\Models\Company;
use App\Models\CompanyEntitlement;
use App\Models\CompanyPreference;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\Invoice;
use App\Models\Partner;
use App\Models\User;
use App\Services\AccountReceivable\AccountReceivableService;
use App\Services\Financial\Banking\BankSlipIssuanceService;
use App\Services\Financial\Banking\BankSlipProviderRegistry;
use App\Services\Financial\Banking\BankSlipWebhookService;
use App\Services\Financial\Banking\Providers\IntegraBancosClientInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class BankSlipWebhookProcessingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private Partner $customer;

    private FinancialAccount $financialAccount;

    private BankSlip $bankSlip;

    private FinancialCategory $receivableCategory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->company = Company::create([
            'name' => 'Empresa Boleto',
            'document_number' => '12345678000188',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $this->user->id,
        ]);
        $this->customer = Partner::create([
            'name' => 'Cliente Boleto',
            'document_type' => 'CPF',
            'document_number' => '12345678901',
            'created_by' => $this->user->id,
        ]);
        $this->financialAccount = FinancialAccount::create([
            'company_id' => $this->company->id,
            'name' => 'Banco do Teste',
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
        $this->receivableCategory = FinancialCategory::create([
            'company_id' => $this->company->id,
            'parent_id' => $parentCategory->id,
            'name' => 'Boletos',
            'allow_receivable' => true,
            'allow_cash_movement' => true,
            'is_active' => true,
            'created_by' => $this->user->id,
        ]);

        $invoice = Invoice::create([
            'customer_id' => $this->customer->id,
            'company_id' => $this->company->id,
            'invoice_number' => '000123',
            'invoice_date' => '2026-09-01',
            'payment_method' => PaymentMethod::BANK_SLIP->value,
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
            'paid_date' => null,
            'due_amount' => 1000,
            'paid_amount' => 0,
            'paid' => false,
            'payment_method' => PaymentMethod::BANK_SLIP->value,
        ]);
        $installment = AccountReceivableInstallment::create([
            'account_receivable_id' => $receivable->id,
            'company_id' => $this->company->id,
            'sequence_number' => '01',
            'status' => AccountReceivableStatus::PENDING->value,
            'due_date' => '2026-10-01',
            'original_amount' => 1000,
            'due_amount' => 1000,
            'received_amount' => 0,
            'balance_amount' => 1000,
            'financial_account_id' => $this->financialAccount->id,
            'financial_category_id' => $this->receivableCategory->id,
        ]);

        $bank = Bank::create(['code' => '999', 'name' => 'Banco de Teste']);
        $provider = BillingProvider::create([
            'key' => 'integrabancos-test',
            'name' => 'IntegraBancos Teste',
            'adapter_class' => 'App\\Services\\Financial\\Banking\\Providers\\IntegraBancosProvider',
        ]);
        $connection = BankAccountConnection::create([
            'company_id' => $this->company->id,
            'financial_account_id' => $this->financialAccount->id,
            'bank_id' => $bank->id,
            'billing_provider_id' => $provider->id,
            'credentials' => ['access_token' => 'test-token'],
            'settings' => [
                'base_url' => 'https://bank.test',
                'webhook_signature' => 'test-signature',
            ],
            'status' => 'active',
        ]);
        $this->bankSlip = BankSlip::create([
            'company_id' => $this->company->id,
            'account_receivable_installment_id' => $installment->id,
            'bank_account_connection_id' => $connection->id,
            'status' => BankSlipStatus::REGISTERED->value,
            'provider_identification' => 'BS-TEST-001',
            'amount' => 1000,
            'due_date' => '2026-10-01',
        ]);
    }

    public function test_registers_incremental_payment_events_and_marks_slip_paid(): void
    {
        $firstEvent = $this->createPaymentEvent('event-1', 400);

        (new ProcessBankSlipWebhookJob($firstEvent->id))->handle(app(AccountReceivableService::class));

        $this->assertDatabaseHas('account_receivable_installment_payments', [
            'bank_slip_id' => $this->bankSlip->id,
            'bank_slip_event_id' => $firstEvent->id,
            'amount' => 40000,
        ]);
        $this->assertSame(600.0, $this->bankSlip->installment()->first()->fresh()->balance_amount);
        $this->assertSame(BankSlipStatus::PARTIALLY_PAID, $this->bankSlip->fresh()->status);

        (new ProcessBankSlipWebhookJob($firstEvent->id))->handle(app(AccountReceivableService::class));

        $this->assertDatabaseCount('account_receivable_installment_payments', 1);

        $secondEvent = $this->createPaymentEvent('event-2', 600);

        (new ProcessBankSlipWebhookJob($secondEvent->id))->handle(app(AccountReceivableService::class));

        $this->assertDatabaseCount('account_receivable_installment_payments', 2);
        $this->assertSame(0.0, $this->bankSlip->installment()->first()->fresh()->balance_amount);
        $this->assertSame(BankSlipStatus::PAID, $this->bankSlip->fresh()->status);
        $this->assertNotNull($firstEvent->fresh()->processed_at);
        $this->assertNotNull($secondEvent->fresh()->processed_at);
    }

    public function test_deduplicates_provider_events_across_connections(): void
    {
        config(['app.debug' => true]);

        $connection = BankAccountConnection::query()->findOrFail($this->bankSlip->bank_account_connection_id);
        $payload = [
            'evento_id' => 'provider-event-1',
            'evento' => 'ATUALIZACAO',
            'identificacao' => $this->bankSlip->provider_identification,
            'cnpj_cpf' => '12345678000188',
            'assinatura' => 'test-signature',
            'status' => ['codigo' => '2', 'mensagem' => 'Registrado'],
        ];
        $service = app(BankSlipWebhookService::class);

        $firstEvent = $service->ingest($connection, $payload, (string) json_encode($payload));
        $secondConnection = BankAccountConnection::create([
            'company_id' => $this->company->id,
            'financial_account_id' => $this->financialAccount->id,
            'bank_id' => $connection->bank_id,
            'billing_provider_id' => $connection->billing_provider_id,
            'credentials' => ['access_token' => 'test-token'],
            'settings' => ['webhook_signature' => 'test-signature'],
            'status' => 'active',
        ]);
        $secondEvent = $service->ingest($secondConnection, $payload, (string) json_encode($payload));

        $this->assertNotNull($firstEvent);
        $this->assertNotNull($secondEvent);
        $this->assertSame($firstEvent->id, $secondEvent->id);
        $this->assertDatabaseCount('bank_slip_events', 1);
    }

    public function test_webhook_endpoint_returns_http_200_when_signature_is_rejected(): void
    {
        $connection = BankAccountConnection::query()->findOrFail($this->bankSlip->bank_account_connection_id);

        $response = $this->postJson(route('webhook.bank-slips', ['connection' => $connection]), [
            'cnpj_cpf' => $this->company->document_number,
            'assinatura' => 'invalid-signature',
        ]);

        $response
            ->assertOk()
            ->assertJson(['ok' => false]);
    }

    public function test_processes_generation_webhook_payload_without_event_id(): void
    {
        config(['app.debug' => true]);

        $payload = [
            'evento' => 'GERACAO',
            'identificacao' => $this->bankSlip->provider_identification,
            'cnpj_cpf' => '12.345.678/0001-88',
            'status' => [
                'codigo' => 2,
                'mensagem' => 'Gerado com sucesso',
            ],
            'detalhes' => [],
            'pdf' => 'https://integrabancos.s3.amazonaws.com/boletos/1/364/boleto-44966508.pdf',
            'qrcode' => 'ZGF...c9PQ==',
            'linha_digitavel' => '00000.00000 00000.000000 00000.000000 0 00000000000000',
            'pix_copia_cola' => null,
            'assinatura' => 'test-signature',
        ];
        $connection = BankAccountConnection::query()->findOrFail($this->bankSlip->bank_account_connection_id);
        $event = app(BankSlipWebhookService::class)->ingest(
            $connection,
            $payload,
            (string) json_encode($payload),
        );

        $this->assertNotNull($event);
        $this->assertSame(BankSlipStatus::REGISTERED, $this->bankSlip->fresh()->status);
        $this->assertSame($payload['pdf'], $this->bankSlip->fresh()->pdf_url);
        $this->assertSame($payload['linha_digitavel'], $this->bankSlip->fresh()->digitable_line);
        $this->assertSame($payload['qrcode'], data_get($this->bankSlip->fresh()->provider_payload, 'qrcode'));
    }

    public function test_cancels_active_slip_when_company_preference_is_enabled(): void
    {
        $client = Mockery::mock(IntegraBancosClientInterface::class);
        $client->shouldReceive('cancel')
            ->once()
            ->with([
                'codigo_banco' => '999',
                'identificacao' => 'BS-TEST-001',
                'motivo' => 'Cancelamento solicitado pela empresa.',
            ])
            ->andReturn([]);
        app()->instance(IntegraBancosClientInterface::class, $client);

        CompanyPreference::setCancelBankSlipsWhenInvoiceCancelled(true, $this->company->id);

        (new ScheduleBankSlipCancellationJob(
            $this->bankSlip->installment()->first()->accountReceivable->invoice_id
        ))->handle();

        $this->assertSame(BankSlipStatus::CANCELED, $this->bankSlip->fresh()->status);
        $this->assertNotNull($this->bankSlip->fresh()->canceled_at);
    }

    public function test_registers_pending_slip_after_invoice_schedule(): void
    {
        $client = Mockery::mock(IntegraBancosClientInterface::class);
        $client->shouldReceive('generate')
            ->once()
            ->with(Mockery::on(function (array $payload): bool {
                return $payload['numero'] === (string) $this->bankSlip->id
                    && $payload['identificacao'] === 'BS-TEST-001'
                    && $payload['codigo_banco'] === '999'
                    && $payload['pagamento']['valor'] === '1000.00'
                    && $payload['pagamento']['data_vencimento'] === '2026-10-01'
                    && $payload['pagador']['cpf'] === '12345678901';
            }))
            ->andReturn([
                'identificacao' => 'BS-TEST-001',
                'id' => 'charge-001',
                'pdf' => 'https://bank.test/boletos/001.pdf',
                'linha_digitavel' => '00190500954014481606906809350314337370000000100',
                'codigo_barras' => '00193373700000001000500940144816060680935031',
                'status' => ['codigo' => '2', 'mensagem' => 'Gerado'],
            ]);
        app()->instance(IntegraBancosClientInterface::class, $client);

        CompanyEntitlement::create([
            'company_id' => $this->company->id,
            'feature' => 'bank_slip_issuance',
            'enabled' => true,
        ]);
        $invoice = $this->bankSlip->installment()->first()->accountReceivable->invoice;
        $invoice->update(['auto_bank_slip_issuance' => true]);
        $this->bankSlip->installment()->update(['auto_bank_slip_issuance' => true]);
        $this->bankSlip->update(['status' => BankSlipStatus::UPDATE_PENDING->value]);

        (new ScheduleBankSlipIssuanceJob($invoice->id))->handle(app(BankSlipIssuanceService::class));

        $this->assertSame(BankSlipStatus::REGISTERED, $this->bankSlip->fresh()->status);
        $this->assertSame('charge-001', $this->bankSlip->fresh()->provider_charge_id);
        $this->assertSame('https://bank.test/boletos/001.pdf', $this->bankSlip->fresh()->pdf_url);
    }

    public function test_marks_slip_as_failed_when_provider_returns_success_false(): void
    {
        $client = Mockery::mock(IntegraBancosClientInterface::class);
        $client->shouldReceive('generate')
            ->once()
            ->andReturn([
                'sucesso' => false,
                'codigo' => 1,
                'mensagem' => 'JSON com erros nos campos.',
                'erros' => [[
                    'campo' => 'numero',
                    'erro' => 'A propriedade numero é obrigatória',
                ]],
            ]);
        app()->instance(IntegraBancosClientInterface::class, $client);

        $this->bankSlip->update(['status' => BankSlipStatus::UPDATE_PENDING->value]);

        (new RegisterBankSlipJob($this->bankSlip->id))->handle(app(BankSlipProviderRegistry::class));

        $bankSlip = $this->bankSlip->fresh();

        $this->assertSame(BankSlipStatus::REGISTRATION_FAILED, $bankSlip->status);
        $this->assertSame('1', $bankSlip->provider_status_code);
        $this->assertSame(
            'JSON com erros nos campos. | numero: A propriedade numero é obrigatória',
            $bankSlip->provider_status_message,
        );
        $this->assertStringContainsString('JSON com erros nos campos.', (string) $bankSlip->last_error);
        $this->assertTrue($bankSlip->providerResponseFailed());
    }

    public function test_exposes_pdf_from_nested_provider_payload_when_column_is_empty(): void
    {
        $this->bankSlip->update([
            'provider_payload' => [
                'sucesso' => true,
                'dados' => [
                    'pdf' => 'https://bank.test/boletos/001.pdf',
                ],
            ],
        ]);

        $this->assertSame('https://bank.test/boletos/001.pdf', $this->bankSlip->fresh()->pdf_url);
    }

    public function test_queries_provider_and_updates_boleto_documents(): void
    {
        $client = Mockery::mock(IntegraBancosClientInterface::class);
        $client->shouldReceive('query')
            ->once()
            ->with(['identificacao' => 'BS-TEST-001'])
            ->andReturn([
                'sucesso' => true,
                'codigo' => 10,
                'mensagem' => 'Cobranca gerada.',
                'dados' => [
                    'identificacao' => 'BS-TEST-001',
                    'pdf' => 'https://bank.test/boletos/001-atualizado.pdf',
                    'linha_digitavel' => '00190500954014481606906809350314337370000000100',
                    'codigo_barras' => '00193373700000001000500940144816060680935031',
                    'status' => [
                        'codigo' => 2,
                        'mensagem' => 'Gerado com sucesso',
                    ],
                ],
            ]);
        app()->instance(IntegraBancosClientInterface::class, $client);

        (new QueryBankSlipJob($this->bankSlip->id))->handle(app(BankSlipProviderRegistry::class));

        $bankSlip = $this->bankSlip->fresh();

        $this->assertSame(BankSlipStatus::REGISTERED, $bankSlip->status);
        $this->assertSame('2', $bankSlip->provider_status_code);
        $this->assertSame('Gerado com sucesso', $bankSlip->provider_status_message);
        $this->assertSame('https://bank.test/boletos/001-atualizado.pdf', $bankSlip->pdf_url);
        $this->assertNotNull($bankSlip->last_synchronized_at);
    }

    public function test_manually_prepares_boleto_for_receivable_without_invoice(): void
    {
        $receivable = $this->bankSlip->installment()->first()->accountReceivable;
        $receivable->update([
            'invoice_id' => null,
            'payment_method' => PaymentMethod::BANK_SLIP->value,
        ]);
        $this->bankSlip->delete();

        $installment = $receivable->installments()->first();
        $installment->update(['financial_account_id' => null]);

        CompanyEntitlement::create([
            'company_id' => $this->company->id,
            'feature' => 'bank_slip_issuance',
            'enabled' => true,
        ]);

        $updatedInstallment = app(AccountReceivableService::class)->updateInstallment($installment, [
            'financial_account_id' => $this->financialAccount->id,
        ]);

        $bankSlip = app(BankSlipIssuanceService::class)->prepareBankSlip(
            $updatedInstallment,
            requireAutomaticIssuance: false,
        );

        $this->assertNotNull($bankSlip);
        $this->assertNull($receivable->fresh()->invoice_id);
        $this->assertSame($this->financialAccount->id, $bankSlip->connection->financial_account_id);
        $this->assertSame($installment->id, $bankSlip->account_receivable_installment_id);
        $this->assertSame(BankSlipStatus::PENDING_REGISTRATION, $bankSlip->status);
    }

    private function createPaymentEvent(string $providerEventId, float $amount): BankSlipEvent
    {
        return BankSlipEvent::create([
            'company_id' => $this->company->id,
            'bank_account_connection_id' => $this->bankSlip->bank_account_connection_id,
            'billing_provider_id' => $this->bankSlip->connection->billing_provider_id,
            'bank_slip_id' => $this->bankSlip->id,
            'provider_event_id' => $providerEventId,
            'event_type' => 'ATUALIZACAO',
            'provider_status_code' => '3',
            'amount' => $amount,
            'payload' => ['status' => ['codigo' => '3']],
            'received_at' => now(),
        ]);
    }
}
