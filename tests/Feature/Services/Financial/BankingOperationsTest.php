<?php

namespace Tests\Feature\Services\Financial;

use App\Filament\Clusters\Financial\Resources\BankingEvents\BankingEventResource;
use App\Filament\Clusters\Financial\Resources\BankSlips\Pages\ListBankSlips;
use App\Filament\Clusters\Financial\Resources\PixCharges\Pages\ListPixCharges;
use App\Jobs\CancelBankSlipJob;
use App\Jobs\CancelPixChargeJob;
use App\Jobs\ProcessBankingEventJob;
use App\Jobs\QueryBankSlipJob;
use App\Jobs\QueryPixChargeJob;
use App\Jobs\RegisterBankSlipJob;
use App\Jobs\UpdateBankSlipJob;
use App\Models\Bank;
use App\Models\BankAccountConnection;
use App\Models\BankingEvent;
use App\Models\BankSlip;
use App\Models\BankSlipEvent;
use App\Models\BillingProvider;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\Partner;
use App\Models\PixCharge;
use App\Models\User;
use App\Services\AccountReceivable\AccountReceivableService;
use App\Services\Company\CompanyOperationalResetService;
use App\Services\Financial\Banking\BankChargeLifecycleService;
use App\Services\Financial\Banking\BankingEventService;
use App\Services\Financial\Banking\BankingOperationService;
use App\Services\Financial\Banking\BankingResolutionService;
use App\Services\Financial\Banking\BankSlipProviderRegistry;
use App\Services\Financial\Banking\BankSlipWebhookService;
use App\Services\Financial\Banking\DTO\BankSlipProviderResult;
use App\Services\Financial\Banking\Providers\IntegraBancosAuthenticationException;
use App\Services\Financial\Banking\Providers\IntegraBancosClientInterface;
use App\Services\Financial\Banking\Providers\IntegraBancosOAuthClient;
use App\Services\Financial\Banking\Providers\IntegraBancosPayloadBuilder;
use App\Services\Financial\Banking\Providers\IntegraBancosResponse;
use App\Services\Financial\Banking\Providers\IntegraBancosSdkTransport;
use App\Services\Financial\Banking\Providers\IntegraBancosTokenManager;
use App\Services\Financial\CashMovementService;
use App\Services\Financial\IgnoreFinancialInstallmentsService;
use App\Services\Financial\Pix\PixProviderRegistry;
use App\Services\Financial\Pix\Providers\IntegraBancosPixClientInterface;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class BankingOperationsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private BankAccountConnection $connection;

    private BankSlip $slip;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->company = Company::factory()->create(['document_number' => '12345678000188']);
        $this->user->companies()->attach($this->company, ['role' => 'member', 'is_active' => true]);
        $this->actingAs($this->user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->company);
        $bank = Bank::firstOrCreate(['code' => '461'], ['name' => 'Asaas']);
        $account = FinancialAccount::create(['company_id' => $this->company->id, 'name' => 'Banco', 'type' => 'bank', 'opening_balance' => 0, 'is_active' => true]);
        $this->connection = BankAccountConnection::create([
            'company_id' => $this->company->id, 'financial_account_id' => $account->id, 'bank_id' => $bank->id,
            'billing_provider_id' => BillingProvider::where('key', 'integrabancos')->firstOrFail()->id,
            'environment' => 'sandbox', 'status' => 'active',
            'credentials' => ['access_token' => 'test', 'secret_key' => str_repeat('k', 32), 'x_api_key' => 'test-api-key'],
        ]);
        $category = FinancialCategory::create(['company_id' => $this->company->id, 'name' => 'Receitas', 'is_active' => true, 'allow_receivable' => true, 'allow_cash_movement' => true]);
        $customer = Partner::create(['name' => 'Cliente', 'document_type' => 'CPF', 'document_number' => '12345678901', 'created_by' => $this->user->id]);
        $customer->companies()->attach($this->company, ['is_active' => true, 'type' => '["customer"]']);
        $service = app(AccountReceivableService::class);
        $receivable = $service->create([
            'company_id' => $this->company->id, 'customer_id' => $customer->id, 'due_date' => '2026-10-01',
            'due_amount' => 100, 'payment_method' => 'boleto', 'financial_category_id' => $category->id,
        ], $this->user->id);
        $this->assertNotNull($receivable, $service->getMessageUser());
        $this->slip = BankSlip::create([
            'company_id' => $this->company->id, 'account_receivable_installment_id' => $receivable->installments()->sole()->id,
            'bank_account_connection_id' => $this->connection->id, 'financial_account_id' => $account->id,
            'status' => 'pending_registration', 'provider_identification' => 'PHASE2-001', 'amount' => 100, 'due_date' => '2026-10-01',
        ]);
    }

    public function test_active_bank_charge_blocks_counterparty_and_ignoring(): void
    {
        $installment = $this->slip->installment;
        $account = $installment->accountReceivable;
        $service = app(AccountReceivableService::class);
        $this->assertNull($service->update($account, ['manual_counterparty_name' => 'Outro pagador', 'counterparty_change_reason' => 'Correção do pagador'], $this->user->id));
        $this->assertArrayHasKey('customer_id', $service->getErrors());
        $this->user->companyAccesses()->create(['company_id' => $this->company->id,
            'permissions' => ['account_receivables.access', 'account_receivables.change_status_without_movement'], 'panels' => ['admin' => true], 'navigation' => []]);
        try {
            app(IgnoreFinancialInstallmentsService::class)->change(new Collection([$account]), true, 'Anterior ao corte', $this->user, $this->company, 'admin');
            $this->fail('Cobrança ativa deve impedir desconsideração.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reason', $e->errors());
        }
        $this->assertNull($installment->fresh()->ignored_at);
        $this->assertSame('2026-10-01', $installment->fresh()->due_date->toDateString());
        $this->assertSame('pending_registration', $this->slip->fresh()->status->value);
    }

    private function observe(array $body, string $operation = 'query', string $source = 'query', BankSlip|PixCharge|null $charge = null): BankingEvent
    {
        $result = new BankSlipProviderResult(
            successful: IntegraBancosResponse::accepted($body), retryable: false, httpStatus: 0,
            statusCode: IntegraBancosResponse::status($body), payload: $body,
            responseCode: IntegraBancosResponse::code($body),
            operationId: isset($body['evento_id']) ? (string) $body['evento_id'] : null,
        );

        return app(BankingEventService::class)->observe($charge ?? $this->slip, $result, $operation, $source);
    }

    private function paid(): array
    {
        return ['sucesso' => true, 'codigo' => 10, 'dados' => ['valor' => 100, 'data_pagamento' => '01/10/2026', 'status' => ['codigo' => 3]]];
    }

    public function test_accepted_registration_is_processing_not_registered_and_freezes_payload(): void
    {
        $client = Mockery::mock(IntegraBancosClientInterface::class);
        $client->shouldReceive('generate')->once()->andReturn(['sucesso' => true, 'codigo' => 4]);
        app()->instance(IntegraBancosClientInterface::class, $client);
        (new RegisterBankSlipJob($this->slip->id))->handle(app(BankSlipProviderRegistry::class));
        $charge = $this->slip->fresh();
        $this->assertSame('processing', $charge->status->value);
        $this->assertNull($charge->registered_at);
        $this->assertNull($charge->provider_status_code);
        $this->assertSame('4', $charge->provider_response_code);
        $this->assertSame('100.00', data_get($charge->request_snapshot, 'pagamento.valor'));
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_timeout_does_not_resend_and_can_be_recovered_by_query(): void
    {
        Queue::fake();
        $client = Mockery::mock(IntegraBancosClientInterface::class);
        $client->shouldReceive('generate')->once()->andThrow(new \RuntimeException('Timeout após envio'));
        $client->shouldReceive('query')->once()->andReturn(['sucesso' => true, 'codigo' => 10, 'dados' => ['status' => ['codigo' => 2]]]);
        app()->instance(IntegraBancosClientInterface::class, $client);
        try {
            (new RegisterBankSlipJob($this->slip->id))->handle(app(BankSlipProviderRegistry::class));
            $this->fail('O timeout deveria provocar recuperação por consulta.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Timeout', $exception->getMessage());
        }
        $this->assertSame('uncertain', $this->slip->fresh()->status->value);
        (new RegisterBankSlipJob($this->slip->id))->handle(app(BankSlipProviderRegistry::class));
        Queue::assertNotPushed(QueryBankSlipJob::class);
        app(BankingOperationService::class)->query($this->slip->fresh());
        Queue::assertPushed(QueryBankSlipJob::class);
        (new QueryBankSlipJob($this->slip->id))->handle(app(BankSlipProviderRegistry::class));
        $this->assertSame('registered', $this->slip->fresh()->status->value);
    }

    public function test_root_code_three_is_duplicate_resource_not_paid(): void
    {
        $this->observe(['sucesso' => false, 'codigo' => 3], 'register', 'request');
        $this->assertSame('processing', $this->slip->fresh()->status->value);
        $this->assertDatabaseCount('account_receivable_installment_payments', 0);
    }

    public function test_cancellation_is_pending_until_charge_confirmation_even_after_event_processed(): void
    {
        $this->slip->update(['status' => 'cancel_pending']);
        $client = Mockery::mock(IntegraBancosClientInterface::class);
        $client->shouldReceive('cancel')->once()->andReturn(['sucesso' => true, 'codigo' => 4, 'evento_id' => 'operation-15']);
        $client->shouldReceive('queryEvent')->once()->with(['codigo_banco' => '461', 'identificacao' => 'PHASE2-001', 'evento_id' => 'operation-15'])
            ->andReturn(['sucesso' => true, 'codigo' => 10, 'dados' => ['tipo' => 'Cancelamento', 'status' => ['codigo' => 5]]]);
        $client->shouldReceive('query')->once()->andReturn(['sucesso' => true, 'codigo' => 10, 'dados' => ['status' => ['codigo' => 2]]]);
        app()->instance(IntegraBancosClientInterface::class, $client);
        (new CancelBankSlipJob($this->slip->id))->handle(app(BankSlipProviderRegistry::class));
        $this->assertSame('cancel_pending', $this->slip->fresh()->status->value);
        $this->assertSame('pending', $this->slip->fresh()->operation_status);
        $this->assertNull($this->slip->fresh()->canceled_at);
        (new QueryBankSlipJob($this->slip->id))->handle(app(BankSlipProviderRegistry::class));
        $this->assertSame('cancel_pending', $this->slip->fresh()->status->value);
        $this->observe(['sucesso' => true, 'codigo' => 10, 'dados' => ['status' => ['codigo' => 4]]]);
        $this->assertSame('canceled', $this->slip->fresh()->status->value);
    }

    public function test_query_recovers_payment_on_bank_date_and_old_generation_does_not_regress_paid(): void
    {
        $event = $this->observe($this->paid());
        $same = $this->observe($this->paid());
        $this->assertSame($event->id, $same->id);
        $payment = $this->slip->installment->payments()->sole();
        $this->assertSame('2026-10-01', $payment->payment_date->toDateString());
        $this->assertSame($event->id, $payment->banking_event_id);
        $this->observe(['sucesso' => true, 'status' => ['codigo' => 2], 'data_hora' => '2026-09-20 12:00:00'], 'GERACAO', 'webhook');
        $this->assertSame('paid', $this->slip->fresh()->status->value);
        $this->assertDatabaseCount('cash_movements', 1);
    }

    public function test_paid_response_without_date_does_not_invent_a_financial_date(): void
    {
        $body = $this->paid();
        unset($body['dados']['data_pagamento']);
        $event = $this->observe($body);
        $this->assertSame('needs_review', $event->status);
        $this->assertDatabaseCount('account_receivable_installment_payments', 0);
        $this->observe(['sucesso' => true, 'codigo' => 10, 'dados' => ['status' => ['codigo' => 2]]]);
        $this->assertSame('needs_review', $this->slip->fresh()->status->value);
    }

    public function test_nominal_query_with_interest_is_reviewed_then_resolved_with_composition(): void
    {
        $this->slip->update(['request_snapshot' => ['pagamento' => ['mora' => ['valor' => '10.00']]]]);
        $event = $this->observe($this->paid());
        $this->assertSame('needs_review', $event->status);
        app(BankingResolutionService::class)->confirmPayment($event, $this->company->id, $this->user, [
            'amount' => 110, 'interest_amount' => 10, 'payment_date' => '2026-10-01',
            'reason' => 'Comprovante confirma principal de 100 e juros de 10.',
        ]);
        $payment = $this->slip->installment->payments()->sole();
        $this->assertSame(110.0, $payment->amount);
        $this->assertSame(10.0, $payment->interest_amount);
        $this->assertSame(0.0, $payment->installment->balance_amount);
        $this->assertSame('resolved', $event->fresh()->status);
    }

    public function test_explicit_manual_receipt_link_does_not_create_another_cash_movement(): void
    {
        $service = app(AccountReceivableService::class);
        $payment = $service->registerInstallmentPayment($this->slip->installment, 100, '2026-10-01', ['financial_account_id' => $this->slip->financial_account_id]);
        $this->assertNotNull($payment, $service->getMessageUser());
        $event = $this->observe($this->paid());
        $this->assertSame('needs_review', $event->status);
        app(BankingResolutionService::class)->confirmPayment($event, $this->company->id, $this->user, [
            'payment_id' => $payment->id, 'amount' => 100, 'payment_date' => '2026-10-01', 'reason' => 'Comprovante e extrato identificam esta baixa manual.',
        ]);
        $this->assertSame($this->slip->id, $payment->fresh()->bank_slip_id);
        $this->assertDatabaseCount('cash_movements', 1);
    }

    public function test_bank_return_preserves_receipt_and_posts_exactly_one_reversal(): void
    {
        $this->observe($this->paid());
        $payment = $this->slip->installment->payments()->sole();
        $event = $this->observe(['sucesso' => true, 'status' => ['codigo' => 7], 'data_hora' => '2026-10-02 12:00:00'], 'ATUALIZACAO', 'webhook');
        $this->assertSame('needs_review', $event->status);
        $this->assertDatabaseCount('cash_movements', 1);
        app(BankingResolutionService::class)->confirmReturn($event, $this->company->id, $this->user, $payment->id, '2026-10-02', 'Devolução integral confirmada no extrato bancário.');
        $this->assertSame(100.0, $payment->fresh()->returned_amount);
        $this->assertSame(100.0, $this->slip->installment->fresh()->balance_amount);
        $this->assertSame('payment_returned', $this->slip->fresh()->status->value);
        $this->assertDatabaseCount('cash_movements', 2);
        $this->assertDatabaseCount('account_receivable_installment_payments', 1);
        app(BankingEventService::class)->process($event->fresh());
        $this->assertDatabaseCount('cash_movements', 2);
        $newPayment = app(AccountReceivableService::class)->registerInstallmentPayment($this->slip->installment, 100, '2026-10-03', ['financial_account_id' => $this->slip->financial_account_id]);
        $this->assertNotNull($newPayment);
        $this->assertSame(0.0, $this->slip->installment->fresh()->balance_amount);
        $this->expectException(ValidationException::class);
        app(BankingResolutionService::class)->confirmReturn($event->fresh(), $this->company->id, $this->user, $payment->id, '2026-10-02', 'Não pode devolver duas vezes o mesmo recebimento.');
    }

    public function test_resolution_is_rejected_for_another_company(): void
    {
        $body = $this->paid();
        unset($body['dados']['data_pagamento']);
        $event = $this->observe($body);
        $this->expectException(HttpException::class);
        app(BankingResolutionService::class)->confirmPayment($event, $this->company->id + 1, $this->user, []);
    }

    public function test_contract_requires_bb_account_fields_and_does_not_claim_bb_pix_support(): void
    {
        $bank = Bank::firstOrCreate(['code' => '001'], ['name' => 'BB']);
        $this->connection->update(['bank_id' => $bank->id]);
        $builder = app(IntegraBancosPayloadBuilder::class);
        try {
            $builder->build($this->slip->fresh());
            $this->fail('Conta e endereço obrigatórios deveriam ser validados.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('conta.convenio', $exception->errors());
            $this->assertArrayHasKey('pagador.endereco.cep', $exception->errors());
        }
        $pix = $this->pix();
        $this->expectException(ValidationException::class);
        $builder->build($pix);
    }

    public function test_immediate_sicoob_pix_requires_key_and_omits_due_date(): void
    {
        $bank = Bank::firstOrCreate(['code' => '756'], ['name' => 'Sicoob']);
        $this->connection->update(['bank_id' => $bank->id, 'settings' => ['pix' => ['pagamento' => ['chave_pix' => 'cliente@empresa.com']]]]);
        $payload = app(IntegraBancosPayloadBuilder::class)->build($this->pix());
        $this->assertSame('cliente@empresa.com', $payload['pagamento']['chave_pix']);
        $this->assertArrayNotHasKey('data_vencimento', $payload['pagamento']);
    }

    public function test_oauth_renewal_is_shared_and_preserves_other_credentials(): void
    {
        $this->connection->update(['credentials' => ['client_id' => 'id', 'client_secret' => 'secret', 'refresh_token' => 'old-refresh', 'x_api_key' => 'keep', 'access_token' => 'old', 'access_token_expires_at' => now()->subMinute()->toIso8601String()]]);
        $client = Mockery::mock(IntegraBancosOAuthClient::class);
        $client->shouldReceive('request')->once()->andReturn(['access_token' => 'renewed', 'refresh_token' => 'new-refresh', 'expires_in' => 900]);
        $manager = new IntegraBancosTokenManager($client);
        $this->assertSame('renewed', $manager->token($this->connection));
        $this->assertSame('renewed', $manager->token($this->connection));
        $this->assertSame('keep', $this->connection->fresh()->credentials['x_api_key']);
        $this->assertSame('new-refresh', $this->connection->fresh()->credentials['refresh_token']);
    }

    public function test_oauth_transport_failure_preserves_diagnostics_without_credentials(): void
    {
        $this->connection->update(['credentials' => ['client_id' => 'id', 'client_secret' => 'private-client-secret']]);
        $client = Mockery::mock(IntegraBancosOAuthClient::class);
        $client->shouldReceive('request')->once()->andThrow(new \RuntimeException(json_encode([
            'message' => 'Erro na Requisição cURL',
            'error' => 'Could not resolve host',
            'http_code' => 0,
            'credentials' => ['client_secret' => 'private-client-secret', 'password' => 'private-password'],
        ])));

        try {
            (new IntegraBancosTokenManager($client))->token($this->connection);
            $this->fail('Falha OAuth deveria ser informada.');
        } catch (IntegraBancosAuthenticationException $exception) {
            $this->assertStringContainsString('Could not resolve host', $exception->getMessage());
            $this->assertStringContainsString('http_code: 0', $exception->getMessage());
            $this->assertStringNotContainsString('private-client-secret', $exception->getMessage());
            $this->assertStringNotContainsString('private-password', $exception->getMessage());
        }
    }

    public function test_oauth_rejection_preserves_provider_reason_without_tokens(): void
    {
        $this->connection->update(['credentials' => ['client_id' => 'id', 'client_secret' => 'secret']]);
        $client = Mockery::mock(IntegraBancosOAuthClient::class);
        $client->shouldReceive('request')->once()->andReturn([
            'error' => 'invalid_client',
            'error_description' => 'Client authentication failed.',
            'refresh_token' => 'private-refresh-token',
        ]);

        try {
            (new IntegraBancosTokenManager($client))->token($this->connection);
            $this->fail('Rejeição OAuth deveria ser informada.');
        } catch (IntegraBancosAuthenticationException $exception) {
            $this->assertStringContainsString('invalid_client', $exception->getMessage());
            $this->assertStringContainsString('Client authentication failed.', $exception->getMessage());
            $this->assertStringNotContainsString('private-refresh-token', $exception->getMessage());
        }
    }

    public function test_explicit_queries_dispatch_jobs_for_boleto_and_pix(): void
    {
        Queue::fake();
        $this->slip->update(['status' => 'uncertain']);
        $pix = $this->pix();
        $pix->update(['status' => 'processing']);
        app(BankingOperationService::class)->query($this->slip->fresh());
        app(BankingOperationService::class)->query($pix->fresh());
        Queue::assertPushed(QueryBankSlipJob::class, 1);
        Queue::assertPushed(QueryPixChargeJob::class, 1);
    }

    public function test_pending_boleto_operations_do_not_dispatch_automatic_queries(): void
    {
        Queue::fake();
        $this->slip->update(['status' => 'cancel_pending', 'operation_kind' => 'cancel', 'operation_status' => 'pending', 'provider_operation_id' => 'cancel-1']);
        (new CancelBankSlipJob($this->slip->id))->handle(app(BankSlipProviderRegistry::class));

        $this->slip->update(['status' => 'update_pending', 'operation_kind' => 'update', 'operation_status' => 'uncertain', 'pending_update' => ['valor' => '120.00']]);
        (new UpdateBankSlipJob($this->slip->id))->handle(app(BankSlipProviderRegistry::class));

        Queue::assertNothingPushed();
    }

    public function test_update_keeps_original_until_bank_query_matches_requested_installment(): void
    {
        Queue::fake();
        $this->slip->update(['status' => 'registered']);
        $service = app(AccountReceivableService::class);
        $this->assertNotNull($service->updateInstallment($this->slip->installment, ['original_amount' => 120, 'due_amount' => 120, 'due_date' => '2026-10-05']));
        app(BankingOperationService::class)->requestUpdate($this->slip, $this->company->id, $this->user, 'Acordo altera valor e vencimento da parcela.');
        Queue::assertPushed(UpdateBankSlipJob::class);
        $client = Mockery::mock(IntegraBancosClientInterface::class);
        $client->shouldReceive('update')->once()->with(['codigo_banco' => '461', 'identificacao' => 'PHASE2-001', 'data_vencimento' => '2026-10-05', 'valor' => '120.00'])
            ->andReturn(['sucesso' => true, 'codigo' => 4, 'evento_id' => 'update-1']);
        app()->instance(IntegraBancosClientInterface::class, $client);
        (new UpdateBankSlipJob($this->slip->id))->handle(app(BankSlipProviderRegistry::class));
        $this->assertSame(100.0, $this->slip->fresh()->amount);
        $this->assertSame('pending', $this->slip->fresh()->operation_status);
        $this->observe(['sucesso' => true, 'codigo' => 10, 'dados' => ['valor' => 120, 'data_vencimento' => '05/10/2026', 'status' => ['codigo' => 2]]]);
        $this->assertSame(120.0, $this->slip->fresh()->amount);
        $this->assertSame('registered', $this->slip->fresh()->status->value);
    }

    public function test_pending_resource_is_company_scoped_and_revoked_user_cannot_resolve(): void
    {
        $body = $this->paid();
        unset($body['dados']['data_pagamento']);
        $event = $this->observe($body);
        $resource = BankingEventResource::class;
        $this->get($resource::getUrl(tenant: $this->company, panel: 'admin'))->assertOk()->assertSee('PHASE2-001');
        $other = Company::factory()->create();
        Filament::setTenant($other);
        $this->assertFalse($resource::getEloquentQuery()->whereKey($event->id)->exists());
        Filament::setTenant($this->company);
        $this->user->companyAccesses()->create(['company_id' => $this->company->id, 'permissions' => [], 'panels' => [], 'navigation' => []]);
        $this->expectException(HttpException::class);
        app(BankingResolutionService::class)->reprocess($event, $this->company->id, $this->user, 'Acesso foi revogado durante a sessão.');
    }

    public function test_pix_webhook_uses_shared_ledger_and_deduplicates_signature_changes(): void
    {
        Queue::fake();
        $pix = $this->pix();
        $body = [
            'evento' => 'ATUALIZACAO', 'identificacao' => $pix->provider_identification,
            'cnpj_cpf' => $this->company->document_number, 'valor' => 100,
            'data_hora' => '2026-10-01 12:00:00', 'status' => ['codigo' => 3],
        ];
        $service = app(BankSlipWebhookService::class);
        $body['assinatura'] = $this->signature();
        $first = $service->ingest($this->connection, $body, json_encode($body));
        $this->assertInstanceOf(BankingEvent::class, $first);
        app(BankingEventService::class)->process($first);
        $body['assinatura'] = $this->signature();
        $again = $service->ingest($this->connection, $body, json_encode($body));
        $this->assertSame($first->id, $again->id);
        app(BankingEventService::class)->process($again);
        $this->assertSame('paid', $pix->fresh()->status->value);
        $this->assertDatabaseCount('bank_slip_events', 0);
        $this->assertDatabaseCount('cash_movements', 1);
        Queue::assertPushed(ProcessBankingEventJob::class);
    }

    public function test_webhook_test_is_acknowledged_without_creating_a_charge_event(): void
    {
        $this->postJson(route('webhook.bank-slips', $this->connection), [
            'evento' => 'TESTE', 'cnpj_cpf' => $this->company->document_number, 'assinatura' => $this->signature(),
        ])->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseCount('bank_slip_events', 0);
        $this->assertDatabaseCount('banking_events', 0);
    }

    public function test_valid_webhook_is_not_acknowledged_when_durable_storage_fails(): void
    {
        BankSlipEvent::creating(function (): void {
            throw new \RuntimeException('Storage indisponível');
        });
        $this->postJson(route('webhook.bank-slips', $this->connection), [
            'evento' => 'GERACAO', 'identificacao' => $this->slip->provider_identification,
            'cnpj_cpf' => $this->company->document_number, 'assinatura' => $this->signature(), 'status' => ['codigo' => 2],
        ])->assertStatus(503);
        $this->assertDatabaseCount('bank_slip_events', 0);
    }

    public function test_settlement_failure_rolls_back_receipt_but_keeps_event_for_retry(): void
    {
        $cash = Mockery::mock(CashMovementService::class)->makePartial();
        $cash->shouldReceive('syncForReceivablePayment')->once()->andReturnUsing(function () use ($cash) {
            $cash->setError('Falha ao gerar caixa');

            return null;
        });
        app()->instance(AccountReceivableService::class, new AccountReceivableService(cashMovementService: $cash));
        try {
            $this->observe($this->paid());
            $this->fail('A falha financeira deveria impedir conclusão do evento.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Falha', $exception->getMessage());
        }
        $this->assertSame('failed', BankingEvent::query()->sole()->status);
        $this->assertDatabaseCount('account_receivable_installment_payments', 0);
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertSame(100.0, $this->slip->installment->fresh()->balance_amount);
    }

    public function test_bank_confirmed_receipt_cannot_be_deleted_or_financially_edited(): void
    {
        $this->observe($this->paid());
        $payment = $this->slip->installment->payments()->sole();
        $service = app(AccountReceivableService::class);
        $this->assertNull($service->updateInstallmentPayment($payment, ['amount' => 90]));
        $this->assertFalse($service->deleteInstallmentPayment($payment));
        $this->assertSame(100.0, $payment->fresh()->amount);
        $this->assertDatabaseCount('cash_movements', 1);
    }

    public function test_resend_requires_two_spaced_not_found_queries_and_preserves_identification(): void
    {
        Queue::fake();
        $this->slip->update(['status' => 'uncertain', 'request_sent_at' => now()->subMinutes(10), 'request_snapshot' => ['identificacao' => 'PHASE2-001', 'pagamento' => ['valor' => '100.00']]]);
        $body = ['sucesso' => false, 'codigo' => 12, 'mensagem' => 'Cobrança não encontrada.'];
        $event = $this->observe($body);
        try {
            app(BankingOperationService::class)->retryRegistration($this->slip, $this->company->id, $this->user, 'Uma única ausência ainda não confirma o resultado.');
            $this->fail('Uma consulta não deveria liberar reenvio.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('charge', $exception->errors());
        }
        $this->travel(2)->minutes();
        $this->observe($body);
        $this->assertSame(2, $event->fresh()->deliveries);
        app(BankingOperationService::class)->retryRegistration($this->slip, $this->company->id, $this->user, 'Ausência confirmada em consultas espaçadas após o timeout.');
        $this->assertSame('pending_registration', $this->slip->fresh()->status->value);
        $this->assertSame('PHASE2-001', $this->slip->fresh()->provider_identification);
        $this->assertSame('PHASE2-001', $this->slip->fresh()->request_snapshot['identificacao']);
        Queue::assertPushed(RegisterBankSlipJob::class);
        $this->travelBack();
    }

    public function test_oauth_does_not_overwrite_credentials_changed_during_refresh(): void
    {
        $this->connection->update(['credentials' => ['client_id' => 'old-client', 'client_secret' => 'old-secret', 'refresh_token' => 'old-refresh']]);
        $client = Mockery::mock(IntegraBancosOAuthClient::class);
        $client->shouldReceive('request')->once()->andReturnUsing(function () {
            $this->connection->fresh()->update(['credentials' => ['client_id' => 'new-client', 'client_secret' => 'new-secret', 'refresh_token' => 'new-refresh']]);

            return ['access_token' => 'stale-result', 'expires_in' => 900];
        });
        try {
            (new IntegraBancosTokenManager($client))->token($this->connection);
            $this->fail('Credenciais alteradas deveriam impedir sobrescrita.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('mudaram', $exception->getMessage());
        }
        $this->assertSame('new-client', $this->connection->fresh()->credentials['client_id']);
        $this->assertArrayNotHasKey('access_token', $this->connection->fresh()->credentials);
    }

    public function test_sdk_transport_retries_only_once_for_explicit_unauthenticated_response(): void
    {
        $tokens = Mockery::mock(IntegraBancosTokenManager::class);
        $tokens->shouldReceive('token')->once()->with($this->connection)->andReturn('first-token');
        $tokens->shouldReceive('token')->once()->with($this->connection, 'first-token')->andReturn('second-token');
        app()->instance(IntegraBancosTokenManager::class, $tokens);
        BankingFakeSdk::$tokens = [];
        $result = app(IntegraBancosSdkTransport::class)->call($this->connection, BankingFakeSdk::class, 'query', []);
        $this->assertTrue($result['sucesso']);
        $this->assertSame(['first-token', 'second-token'], BankingFakeSdk::$tokens);
    }

    public function test_partial_webhook_requires_homologated_amount_semantics(): void
    {
        $event = $this->observe(['sucesso' => true, 'status' => ['codigo' => 3], 'valor' => 40, 'data_hora' => '2026-10-01 12:00:00'], 'ATUALIZACAO', 'webhook');
        $this->assertSame('needs_review', $event->status);
        $this->assertDatabaseCount('account_receivable_installment_payments', 0);
        $this->assertSame('unknown', data_get($event->payload, 'amount_semantics'));
    }

    public function test_cumulative_webhooks_apply_only_the_delta_and_ignore_same_total(): void
    {
        $this->connection->update(['settings' => ['webhook_amount_mode' => 'cumulative']]);
        $this->observe(['sucesso' => true, 'status' => ['codigo' => 3], 'valor' => 40, 'data_hora' => '2026-10-01 12:00:00'], 'ATUALIZACAO', 'webhook');
        $this->observe(['sucesso' => true, 'status' => ['codigo' => 3], 'valor' => 40, 'data_hora' => '2026-10-01 12:01:00'], 'ATUALIZACAO', 'webhook');
        $this->assertDatabaseCount('account_receivable_installment_payments', 1);
        $this->assertSame('partially_paid', $this->slip->fresh()->status->value);
        $this->observe(['sucesso' => true, 'status' => ['codigo' => 3], 'valor' => 100, 'data_hora' => '2026-10-02 12:00:00'], 'ATUALIZACAO', 'webhook');
        $this->assertSame([40.0, 60.0], $this->slip->installment->payments()->orderBy('id')->get()->pluck('amount')->all());
        $this->assertSame('paid', $this->slip->fresh()->status->value);
    }

    public function test_settling_the_installment_does_not_claim_an_underpaid_charge_is_paid(): void
    {
        $this->connection->update(['settings' => ['webhook_amount_mode' => 'incremental']]);
        $manual = app(AccountReceivableService::class)->registerInstallmentPayment($this->slip->installment, 40, '2026-10-01', ['financial_account_id' => $this->slip->financial_account_id]);
        $this->assertNotNull($manual);
        $this->observe(['sucesso' => true, 'status' => ['codigo' => 3], 'valor' => 60, 'data_hora' => '2026-10-01 12:00:00'], 'ATUALIZACAO', 'webhook');
        $this->assertSame(0.0, $this->slip->installment->fresh()->balance_amount);
        $this->assertSame('partially_paid', $this->slip->fresh()->status->value);
        $this->assertNull($this->slip->fresh()->paid_at);
    }

    public function test_expired_refresh_token_can_use_one_password_grant_fallback(): void
    {
        $this->connection->update(['credentials' => ['client_id' => 'id', 'client_secret' => 'secret', 'username' => 'user', 'password' => 'pass', 'refresh_token' => 'expired']]);
        $client = Mockery::mock(IntegraBancosOAuthClient::class);
        $client->shouldReceive('request')->once()->with(Mockery::type('array'), 'expired', false)->andReturn(['error' => 'invalid_grant']);
        $client->shouldReceive('request')->once()->with(Mockery::type('array'), null, false)->andReturn(['access_token' => 'password-token', 'expires_in' => 900]);
        $this->assertSame('password-token', (new IntegraBancosTokenManager($client))->token($this->connection));
    }

    public function test_used_connection_cannot_be_redirected_to_another_bank_or_environment(): void
    {
        $this->slip->update(['status' => 'registered']);
        $this->expectException(ValidationException::class);
        $this->connection->update(['environment' => 'production']);
    }

    public function test_reset_includes_event_ledger_and_its_payment_foreign_key_in_delete_order(): void
    {
        $event = $this->observe($this->paid());
        app(CompanyOperationalResetService::class)->execute($this->company->id);
        $this->assertNull($event->fresh());
        $this->assertDatabaseCount('account_receivable_installment_payments', 0);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_explicit_duplicate_resolution_keeps_original_bank_event_and_cash(): void
    {
        $original = $this->observe($this->paid());
        $payment = $this->slip->installment->payments()->sole();
        $duplicate = $this->observe(['sucesso' => true, 'status' => ['codigo' => 3], 'valor' => 100], 'ATUALIZACAO', 'webhook');
        $this->assertSame('needs_review', $duplicate->status);
        app(BankingResolutionService::class)->confirmPayment($duplicate, $this->company->id, $this->user, [
            'payment_id' => $payment->id, 'amount' => 100, 'payment_date' => '2026-10-01',
            'reason' => 'Extrato confirma que consulta e webhook representam o mesmo pagamento.',
        ]);
        $this->assertSame('resolved', $duplicate->fresh()->status);
        $this->assertSame($original->id, $payment->fresh()->banking_event_id);
        $this->assertSame($payment->id, data_get($duplicate->fresh()->payload, 'resolution.represented_payment_id'));
        $this->assertDatabaseCount('cash_movements', 1);
    }

    public function test_identical_validation_errors_from_distinct_attempts_are_not_lost_as_duplicates(): void
    {
        Queue::fake();
        $client = Mockery::mock(IntegraBancosClientInterface::class);
        $client->shouldReceive('generate')->twice()->andReturn(['sucesso' => false, 'codigo' => 1, 'mensagem' => 'Dados inválidos.']);
        app()->instance(IntegraBancosClientInterface::class, $client);
        (new RegisterBankSlipJob($this->slip->id))->handle(app(BankSlipProviderRegistry::class));
        $attempt = $this->slip->fresh()->operation_attempt_id;
        app(BankingOperationService::class)->retryRegistration($this->slip, $this->company->id, $this->user, 'Dados cadastrais foram revisados para nova tentativa.');
        (new RegisterBankSlipJob($this->slip->id))->handle(app(BankSlipProviderRegistry::class));
        $this->assertSame('registration_failed', $this->slip->fresh()->status->value);
        $this->assertNotSame($attempt, $this->slip->fresh()->operation_attempt_id);
        $this->assertSame(2, BankingEvent::where('source', 'request')->count());
    }

    private function signature(): string
    {
        $key = $this->connection->credentials['secret_key'];
        $iv = random_bytes(16);
        $cipher = openssl_encrypt((string) time(), 'aes-128-cbc', $key, OPENSSL_RAW_DATA, $iv);

        return base64_encode($iv.hash_hmac('sha256', $cipher, $key, true).$cipher);
    }

    public function test_manual_cancellation_is_separate_from_removal_and_waits_for_bank_confirmation(): void
    {
        Queue::fake();
        $this->slip->update(['status' => 'registered']);
        $service = app(BankChargeLifecycleService::class);
        $service->cancel($this->slip, $this->company->id, $this->user, 'Cancelamento solicitado pelo cliente.');
        Queue::assertPushed(CancelBankSlipJob::class);
        $this->assertSame('cancel_pending', $this->slip->fresh()->status->value);
        $this->assertFalse($this->slip->fresh()->trashed());
        try {
            $service->remove($this->slip, $this->company->id, $this->user, 'Remoção solicitada pelo cliente.');
            $this->fail('Não deve remover antes da confirmação.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('charge', $exception->errors());
        }
        $this->observe(['sucesso' => true, 'codigo' => 10, 'status' => ['codigo' => 4]], 'query');
        $this->assertFalse($this->slip->fresh()->trashed());
        $service->remove($this->slip, $this->company->id, $this->user, 'Remoção após cancelamento confirmado.');
        $this->assertSoftDeleted($this->slip);
    }

    public function test_both_charge_types_support_trash_then_permanent_removal_preserving_events(): void
    {
        $service = app(BankChargeLifecycleService::class);
        foreach ([$this->slip, $this->pix()] as $charge) {
            $this->observe(['sucesso' => true, 'codigo' => 10, 'status' => ['codigo' => 4]], charge: $charge);
            $field = $charge instanceof BankSlip ? 'bank_slip_id' : 'pix_charge_id';
            $event = BankingEvent::where($field, $charge->id)->sole();
            try {
                $service->remove($charge, $this->company->id, $this->user, 'Exclusão definitiva da cobrança.', permanent: true);
                $this->fail('Exclusão definitiva deve exigir lixeira.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('charge', $exception->errors());
            }
            $service->remove($charge, $this->company->id, $this->user, 'Remoção de cobrança cancelada.');
            $this->assertSoftDeleted($charge);
            $service->remove($charge, $this->company->id, $this->user, 'Exclusão definitiva da cobrança.', permanent: true);
            $this->assertDatabaseMissing($charge->getTable(), ['id' => $charge->id]);
            $this->assertNull($event->fresh()->{$field});
            $this->assertSame($charge->id, data_get($event->fresh()->payload, 'removed_charge.id'));
        }
    }

    public function test_removed_charge_receiving_late_payment_goes_to_review_without_settlement(): void
    {
        $this->slip->update(['status' => 'canceled']);
        app(BankChargeLifecycleService::class)->remove(
            $this->slip, $this->company->id, $this->user, 'Remoção após cancelamento confirmado.',
        );
        $event = $this->observe(['sucesso' => true, 'codigo' => 10, 'status' => ['codigo' => 3], 'valor' => 100]);
        $this->assertSame('needs_review', $event->status);
        $this->assertSame(0, $this->slip->installment->payments()->count());
        $this->expectException(ValidationException::class);
        app(BankChargeLifecycleService::class)->remove(
            $this->slip, $this->company->id, $this->user, 'Exclusão definitiva com evento pendente.', permanent: true,
        );
    }

    public function test_lifecycle_actions_reject_other_tenant_and_payments(): void
    {
        $service = app(BankChargeLifecycleService::class);
        $this->slip->update(['status' => 'canceled']);
        try {
            $service->remove($this->slip, Company::factory()->create()->id, $this->user, 'Remoção de outra empresa.');
            $this->fail('Não pode remover cobrança de outra empresa.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->slip->update(['status' => 'registered']);
        $this->observe(['sucesso' => true, 'codigo' => 10, 'status' => ['codigo' => 3], 'valor' => 100]);
        $this->expectException(ValidationException::class);
        $service->remove($this->slip, $this->company->id, $this->user, 'Não deve excluir cobrança recebida.');
    }

    public function test_pix_cancellation_reports_missing_sdk_support_without_changing_the_charge(): void
    {
        Queue::fake();
        $pix = $this->pix();
        try {
            app(BankChargeLifecycleService::class)->cancel(
                $pix, $this->company->id, $this->user, 'Cancelamento solicitado pelo cliente.',
            );
            $this->fail('O SDK instalado não possui cancelamento PIX.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('não oferece cancelamento PIX', $exception->errors()['charge'][0]);
        }
        $this->assertSame('registered', $pix->fresh()->status->value);
        Queue::assertNotPushed(CancelPixChargeJob::class);
    }

    public function test_pix_cancellation_adapter_waits_for_charge_confirmation_without_removing_it(): void
    {
        Queue::fake();
        $pix = $this->pix();
        $client = Mockery::mock(IntegraBancosPixClientInterface::class);
        $client->shouldReceive('assertCancellationSupported')->once();
        $client->shouldReceive('cancel')->once()->with(Mockery::on(fn (array $payload): bool => $payload['identificacao'] === $pix->provider_identification))
            ->andReturn(['sucesso' => true, 'codigo' => 4]);
        $client->shouldReceive('query')->once()->andReturn(['sucesso' => true, 'codigo' => 10, 'status' => ['codigo' => 4]]);
        app()->instance(IntegraBancosPixClientInterface::class, $client);
        app(BankChargeLifecycleService::class)->cancel(
            $pix, $this->company->id, $this->user, 'Cancelamento solicitado pelo cliente.',
        );
        Queue::assertPushed(CancelPixChargeJob::class);
        $job = new CancelPixChargeJob($pix->id);
        $registry = app(PixProviderRegistry::class);
        $job->handle($registry);
        $job->handle($registry);
        $this->assertSame('cancel_pending', $pix->fresh()->status->value);
        $this->assertSame('pending', $pix->fresh()->operation_status);
        (new QueryPixChargeJob($pix->id))->handle($registry);
        $this->assertSame('canceled', $pix->fresh()->status->value);
        $this->assertNotNull($pix->fresh()->canceled_at);
        $this->assertFalse($pix->fresh()->trashed());
    }

    public function test_cancel_return_processing_failure_is_uncertain_and_query_recovers_without_resending(): void
    {
        $this->slip->update(['status' => 'cancel_pending', 'operation_kind' => 'cancel', 'operation_status' => 'ready']);
        $client = Mockery::mock(IntegraBancosClientInterface::class);
        $client->shouldReceive('cancel')->once()->andReturn(['sucesso' => true, 'codigo' => 4]);
        $client->shouldReceive('query')->once()->andReturn(['sucesso' => true, 'codigo' => 10, 'dados' => ['status' => ['codigo' => 4]]]);
        app()->instance(IntegraBancosClientInterface::class, $client);

        // Simulate a failure persisting the bank response after the external call.
        BankingEvent::creating(function (BankingEvent $event): void {
            if ($event->source === 'request') {
                throw new \RuntimeException('Falha ao gravar retorno bancário.');
            }
        });
        $job = new CancelBankSlipJob($this->slip->id);
        try {
            $job->handle(app(BankSlipProviderRegistry::class));
            $this->fail('Deve propagar a falha de persistência.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Falha ao gravar retorno bancário.', $exception->getMessage());
        }
        $this->assertSame('uncertain', $this->slip->fresh()->operation_status);
        $this->assertStringContainsString('Consulte o provedor', $this->slip->fresh()->last_error);
        $job->handle(app(BankSlipProviderRegistry::class));
        (new QueryBankSlipJob($this->slip->id))->handle(app(BankSlipProviderRegistry::class));
        $this->assertSame('canceled', $this->slip->fresh()->status->value);
        $this->assertSame('confirmed', $this->slip->fresh()->operation_status);
        $this->assertNull($this->slip->fresh()->last_error);
    }

    public function test_query_recovers_old_sending_state_without_treating_generated_as_canceled(): void
    {
        $this->slip->update(['status' => 'cancel_pending', 'operation_kind' => 'cancel', 'operation_status' => 'sending']);
        $client = Mockery::mock(IntegraBancosClientInterface::class);
        $client->shouldReceive('query')->twice()->andReturn(
            ['sucesso' => true, 'codigo' => 10, 'dados' => ['status' => ['codigo' => 2]]],
            ['sucesso' => true, 'codigo' => 10, 'dados' => ['status' => ['codigo' => 4]]],
        );
        app()->instance(IntegraBancosClientInterface::class, $client);
        $job = new QueryBankSlipJob($this->slip->id);
        $job->handle(app(BankSlipProviderRegistry::class));
        $this->assertSame('cancel_pending', $this->slip->fresh()->status->value);
        $this->assertSame('uncertain', $this->slip->fresh()->operation_status);
        $this->assertNull($this->slip->fresh()->canceled_at);
        $this->assertStringContainsString('não foi confirmado', $this->slip->fresh()->last_error);
        $job->handle(app(BankSlipProviderRegistry::class));
        $this->assertSame('canceled', $this->slip->fresh()->status->value);
        $this->assertSame('confirmed', $this->slip->fresh()->operation_status);
    }

    public function test_canceled_query_response_recovers_stale_model_error_and_uses_bank_cancellation_date(): void
    {
        $this->slip->update([
            'status' => 'cancel_pending', 'operation_kind' => 'cancel', 'operation_status' => 'sending',
            'last_error' => 'Call to undefined method App\\Models\\BankSlip::withTrashed()',
            'provider_status_code' => '2', 'provider_payload' => ['evento' => 'GERACAO', 'status' => ['codigo' => '2']],
        ]);
        $response = [
            'sucesso' => true, 'codigo' => 10, 'mensagem' => 'Cobrança cancelada',
            'dados' => [
                'valor' => 100, 'data_pagamento' => null, 'data_cancelamento' => '2026-10-27 13:17:25',
                'status' => ['codigo' => 4, 'mensagem' => 'Cancelado'], 'detalhes' => [],
            ],
        ];
        $client = Mockery::mock(IntegraBancosClientInterface::class);
        $client->shouldReceive('query')->once()->andReturn($response);
        app()->instance(IntegraBancosClientInterface::class, $client);

        (new QueryBankSlipJob($this->slip->id))->handle(app(BankSlipProviderRegistry::class));

        $charge = $this->slip->fresh();
        $this->assertSame('canceled', $charge->status->value);
        $this->assertSame('confirmed', $charge->operation_status);
        $this->assertSame('4', $charge->provider_status_code);
        $this->assertSame('Cancelado', $charge->provider_status_message);
        $this->assertSame('2026-10-27 13:17:25', $charge->canceled_at->format('Y-m-d H:i:s'));
        $this->assertNull($charge->last_error);
        $this->assertSame($response, $charge->provider_payload);
        $this->assertDatabaseCount('account_receivable_installment_payments', 0);
        $this->assertSame('processed', BankingEvent::where('source', 'query')->sole()->status);
    }

    public function test_charge_removal_blockers_are_shown_in_a_visible_notification(): void
    {
        foreach ([[ListBankSlips::class, $this->slip], [ListPixCharges::class, $this->pix()]] as [$page, $charge]) {
            $charge->update(['status' => 'registered']);
            Livewire::test($page)
                ->callTableAction('remove_charge', $charge, ['reason' => 'Remoção solicitada pelo cliente.'])
                ->assertNotified(Notification::make()
                    ->title('Não foi possível concluir a ação.')
                    ->body('Confirme o cancelamento no provedor antes de remover a cobrança.')
                    ->danger()->persistent())
                ->assertSet('mountedActions.0.name', 'remove_charge')
                ->assertCanSeeTableRecords([$charge]);
            $this->assertFalse($charge->fresh()->trashed());
        }
    }

    public function test_pix_cancellation_modal_displays_the_provider_limitation(): void
    {
        Queue::fake();
        $pix = $this->pix();
        Livewire::test(ListPixCharges::class)
            ->callTableAction('cancel_charge', $pix, ['reason' => 'Cancelamento solicitado pelo cliente.'])
            ->assertNotified(Notification::make()
                ->title('Não foi possível concluir a ação.')
                ->body('O SDK IntegraBancos instalado não oferece cancelamento PIX. É necessário obter do provedor o contrato/API de cancelamento antes de habilitar esta operação.')
                ->danger()->persistent())
            ->assertSet('mountedActions.0.name', 'cancel_charge');
        $this->assertSame('registered', $pix->fresh()->status->value);
        Queue::assertNotPushed(CancelPixChargeJob::class);
    }

    public function test_permanent_removal_modal_displays_pending_event_blockers(): void
    {
        $this->slip->update(['status' => 'canceled']);
        app(BankChargeLifecycleService::class)->remove($this->slip, $this->company->id, $this->user, 'Remoção após cancelamento confirmado.');
        $this->observe(['sucesso' => true, 'codigo' => 10, 'status' => ['codigo' => 3], 'valor' => 100]);

        Livewire::test(ListBankSlips::class)
            ->filterTable('trashed', false)
            ->callTableAction('force_remove_charge', $this->slip, ['reason' => 'Exclusão definitiva da cobrança.'])
            ->assertNotified(Notification::make()
                ->title('Não foi possível concluir a ação.')
                ->body('Trate os recebimentos e eventos bancários pendentes antes desta ação.')
                ->danger()->persistent())
            ->assertSet('mountedActions.0.name', 'force_remove_charge');
        $this->assertSoftDeleted($this->slip);
    }

    public function test_charge_lists_expose_separate_actions_and_permanent_removal_only_in_trash(): void
    {
        foreach ([
            [ListBankSlips::class, $this->slip],
            [ListPixCharges::class, $this->pix()],
        ] as [$page, $charge]) {
            $charge->update(['status' => 'registered']);
            $table = Livewire::test($page)
                ->assertCanSeeTableRecords([$charge])
                ->assertTableActionVisible('cancel_charge', $charge)
                ->assertTableActionVisible('remove_charge', $charge)
                ->assertTableActionHidden('force_remove_charge', $charge);
            $charge->update(['status' => 'canceled']);
            $table->callTableAction('remove_charge', $charge, ['reason' => 'Remoção após cancelamento no banco.'])
                ->assertHasNoTableActionErrors()
                ->assertCanNotSeeTableRecords([$charge])
                ->filterTable('trashed', false)
                ->assertCanSeeTableRecords([$charge])
                ->assertTableActionHidden('cancel_charge', $charge)
                ->assertTableActionHidden('remove_charge', $charge)
                ->assertTableActionVisible('force_remove_charge', $charge)
                ->callTableAction('force_remove_charge', $charge, ['reason' => 'Exclusão definitiva do registro cancelado.'])
                ->assertHasNoTableActionErrors()
                ->assertCanNotSeeTableRecords([$charge]);
            $this->assertDatabaseMissing($charge->getTable(), ['id' => $charge->id]);
        }
    }

    private function pix(): PixCharge
    {
        return PixCharge::create([
            'company_id' => $this->company->id, 'account_receivable_installment_id' => $this->slip->account_receivable_installment_id,
            'bank_account_connection_id' => $this->connection->id, 'financial_account_id' => $this->slip->financial_account_id,
            'status' => 'registered', 'provider_identification' => 'PHASE2-PIX', 'amount' => 100, 'due_date' => '2026-10-01',
        ]);
    }
}

class BankingFakeSdk
{
    public static array $tokens = [];

    public function __construct(array $config)
    {
        self::$tokens[] = $config['access_token'];
    }

    public function query(array $payload): object
    {
        return (object) (count(self::$tokens) === 1 ? ['sucesso' => false, 'codigo' => 23] : ['sucesso' => true, 'codigo' => 10]);
    }
}
