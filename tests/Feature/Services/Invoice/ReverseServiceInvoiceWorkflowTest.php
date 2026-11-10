<?php

namespace Tests\Feature\Services\Invoice;

use App\Enum\FiscalDocument\NfeStatus;
use App\Enum\FiscalDocument\Status as FiscalStatus;
use App\Enum\Invoice\CancellationReason;
use App\Enum\Invoice\OperationStatus;
use App\Enum\Invoice\Status;
use App\Enum\Payment\Condition;
use App\Enum\Payment\Method;
use App\Enum\ServiceOrder\State;
use App\Filament\Clusters\Financial\Resources\Invoices\Pages\EditInvoice;
use App\Jobs\CancelBankSlipJob;
use App\Jobs\ProcessInvoiceReversalJob;
use App\Models\Bank;
use App\Models\BankAccountConnection;
use App\Models\BankSlip;
use App\Models\BillingProvider;
use App\Models\CashMovement;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\FiscalDocument;
use App\Models\Invoice;
use App\Models\InvoiceOperation;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\AccountReceivable\AccountReceivableGenerationService;
use App\Services\AccountReceivable\AccountReceivableService;
use App\Services\FiscalDocument\NfseDocumentService;
use App\Services\Invoice\Actions\ConfirmInvoiceAction;
use App\Services\Invoice\InvoiceFinancialLinkService;
use App\Services\Invoice\InvoiceReceiptReversalService;
use App\Services\Invoice\ReverseServiceInvoiceWorkflow;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ReverseServiceInvoiceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_generated_title_is_identified_and_integral_reversal_preserves_invoice_values_and_releases_orders(): void
    {
        [$invoice, $order, $owner] = $this->confirmed();
        $title = $invoice->accountReceivables()->sole();
        $this->assertSame('generated', $invoice->receivableLinks()->sole()->origin);
        $movements = StockMovement::count();
        $operation = $this->request($invoice, $owner);
        app(ReverseServiceInvoiceWorkflow::class)->process($operation);
        $this->assertSame(OperationStatus::COMPLETED, $operation->fresh()->status, $operation->fresh()->last_message);
        $this->assertSame(Status::CANCELLED, $invoice->fresh()->status);
        $this->assertSame(CancellationReason::REBILLING, $invoice->fresh()->cancellation_reason);
        $this->assertSame(1000.0, $invoice->fresh()->total_amount);
        $this->assertSame('cancelled', $title->fresh()->status->value);
        $this->assertSame('cancelled', $title->installments()->sole()->status->value);
        $this->assertSame(State::CLOSED, $order->fresh()->status);
        $this->assertNull($order->fresh()->invoice_id);
        $this->assertSame('billing_reversed', $invoice->latestConfirmation->sources()->sole()->release_reason);
        $this->assertSame('billing_reversed', $invoice->receivableLinks()->sole()->release_reason);
        $this->assertSame($movements, StockMovement::count());
        $this->assertSame(1, Invoice::count());
        app(ReverseServiceInvoiceWorkflow::class)->process($operation);
        $this->assertSame(1, InvoiceOperation::count());
    }

    public function test_preexisting_title_and_its_installments_survive_while_only_the_association_is_released(): void
    {
        [$invoice, $order, $owner] = $this->confirmed(false);
        $document = $this->document($invoice, NfeStatus::CANCELED);
        $category = FinancialCategory::where('company_id', $invoice->company_id)->firstOrFail();
        $service = app(AccountReceivableService::class);
        $title = $service->create(['company_id' => $invoice->company_id, 'customer_id' => $invoice->customer_id, 'due_date' => today()->addMonth()->toDateString(), 'due_amount' => 1000, 'financial_category_id' => $category->id, 'installment_count' => 2], $owner->id);
        $this->assertNotNull($title, (string) $service->getMessage());
        $installments = $title->installments()->pluck('id')->all();
        app(InvoiceFinancialLinkService::class)->attachExisting($invoice, $title, $owner->id, $document->id);
        $this->assertSame('linked_existing', $invoice->receivableLinks()->sole()->origin);
        $operation = $this->request($invoice, $owner);
        app(ReverseServiceInvoiceWorkflow::class)->process($operation);
        $this->assertSame(OperationStatus::COMPLETED, $operation->fresh()->status, $operation->fresh()->last_message);
        $this->assertNull($title->fresh()->invoice_id);
        $this->assertNull($title->fresh()->fiscal_document_id);
        $this->assertSame('pending', $title->fresh()->status->value);
        $this->assertSame($installments, $title->installments()->pluck('id')->all());
        $this->assertSame(1000.0, (float) $title->fresh()->due_amount);
        $this->assertSame(State::CLOSED, $order->fresh()->status);
    }

    public function test_existing_receipt_blocks_request_and_manual_reversal_preserves_payment_and_exact_cash_lineage(): void
    {
        [$invoice, $order, $owner] = $this->confirmed();
        $this->actingAs($owner);
        $account = FinancialAccount::create(['company_id' => $invoice->company_id, 'name' => 'Banco', 'type' => 'bank', 'is_active' => true]);
        $title = $invoice->accountReceivables()->sole();
        $service = app(AccountReceivableService::class);
        $payment = $service->registerInstallmentPayment($title->installments()->sole(), 1000, today()->toDateString(), ['financial_account_id' => $account->id]);
        $this->assertNotNull($payment, (string) $service->getMessage());
        try {
            $this->request($invoice, $owner);
            $this->fail('Recebimento vigente deve impedir reversão.');
        } catch (ValidationException) {
            $this->assertSame(0, InvoiceOperation::count());
            $this->assertSame($invoice->id, $order->fresh()->invoice_id);
        }
        $this->assertTrue($service->deleteInstallmentPayment($payment), (string) $service->getMessage());
        $this->assertSame(1000.0, (float) $payment->fresh()->returned_amount);
        $original = CashMovement::where('origin_type', $payment::class)->where('origin_id', $payment->id)->sole();
        $this->assertNotNull($original->reversed_at);
        $this->assertSame($original->id, $original->reversals()->sole()->reversal_of_id);
        $this->assertSame([], app(InvoiceReceiptReversalService::class)->blockers($title->fresh()));
        $operation = $this->request($invoice, $owner);
        app(ReverseServiceInvoiceWorkflow::class)->process($operation);
        $this->assertSame(OperationStatus::COMPLETED, $operation->fresh()->status, $operation->fresh()->last_message);
        $snapshot = $invoice->receivableLinks()->sole()->release_snapshot;
        $this->assertSame($payment->id, $snapshot['payments'][0]['id']);
        $this->assertSame($original->id, $snapshot['movements'][0]['reversals'][0]['reversal_of_id']);
    }

    public function test_payment_flag_without_its_cash_reversal_does_not_release_invoice(): void
    {
        [$invoice, , $owner] = $this->confirmed();
        $account = FinancialAccount::create(['company_id' => $invoice->company_id, 'name' => 'Banco', 'type' => 'bank', 'is_active' => true]);
        $service = app(AccountReceivableService::class);
        $title = $invoice->accountReceivables()->sole();
        $payment = $service->registerInstallmentPayment($title->installments()->sole(), 100, today()->toDateString(), ['financial_account_id' => $account->id]);
        $payment->update(['returned_amount' => $payment->amount]);
        $service->resynchronizeAfterBankReturn($payment->installment->fresh());
        $this->expectException(ValidationException::class);
        $this->request($invoice, $owner);
    }

    public function test_all_nfse_are_cancelled_and_receipt_arriving_during_cancellation_interrupts_release(): void
    {
        [$invoice, $order, $owner] = $this->confirmed();
        $document = $this->document($invoice, NfeStatus::AUTHORIZED);
        $operation = $this->request($invoice, $owner);
        $mock = $this->mock(NfseDocumentService::class);
        $mock->shouldReceive('cancelar')->once()->andReturnUsing(function ($document) use ($invoice): bool {
            $document->update(['status' => FiscalStatus::CANCELLED, 'nfse_status' => NfeStatus::CANCELED]);
            $service = app(AccountReceivableService::class);
            $payment = $service->registerInstallmentPayment($invoice->accountReceivables()->sole()->installments()->sole(), 100, today()->toDateString(), []);
            $this->assertNotNull($payment, (string) $service->getMessage());

            return true;
        });
        app(ReverseServiceInvoiceWorkflow::class)->process($operation);
        $this->assertSame(OperationStatus::NEEDS_INTERVENTION, $operation->fresh()->status);
        $this->assertSame($invoice->id, $order->fresh()->invoice_id);
        $this->assertSame(Status::CONFIRMED, $invoice->fresh()->status);
        $this->assertTrue($document->fresh()->isFiscalCancellationConfirmed());
    }

    public function test_provider_refusal_preserves_link_and_can_resume_without_repeating_completed_cancellations(): void
    {
        [$invoice, $order, $owner] = $this->confirmed(false);
        $done = $this->document($invoice, NfeStatus::CANCELED);
        $pending = $this->document($invoice, NfeStatus::AUTHORIZED);
        $operation = $this->request($invoice, $owner);
        $mock = $this->mock(NfseDocumentService::class);
        $mock->shouldReceive('cancelar')->once()->withArgs(fn ($record) => $record->id === $pending->id)->andReturnFalse();
        $mock->shouldReceive('getMessage')->andReturn('Cancelamento recusado pela prefeitura.');
        app(ReverseServiceInvoiceWorkflow::class)->process($operation);
        $this->assertSame(OperationStatus::NEEDS_INTERVENTION, $operation->fresh()->status);
        $this->assertSame($invoice->id, $order->fresh()->invoice_id);
        $pending->update(['status' => FiscalStatus::CANCELLED, 'nfse_status' => NfeStatus::CANCELED]);
        app(ReverseServiceInvoiceWorkflow::class)->process($operation);
        $this->assertSame(OperationStatus::COMPLETED, $operation->fresh()->status, $operation->fresh()->last_message);
    }

    public function test_registered_bank_charge_keeps_order_locked_until_bank_confirmation(): void
    {
        [$invoice, $order, $owner] = $this->confirmed();
        Bus::fake([CancelBankSlipJob::class]);
        $this->actingAs($owner);
        $slip = $this->slip($invoice);
        $operation = $this->request($invoice, $owner);
        app(ReverseServiceInvoiceWorkflow::class)->process($operation);
        $this->assertSame(OperationStatus::AWAITING_EXTERNAL, $operation->fresh()->status, $operation->fresh()->last_message);
        $this->assertSame($invoice->id, $order->fresh()->invoice_id);
        $this->assertSame('cancel_pending', $slip->fresh()->status->value);
        Bus::assertDispatched(CancelBankSlipJob::class, 1);
        $slip->update(['status' => 'canceled', 'operation_status' => 'confirmed']);
        app(ReverseServiceInvoiceWorkflow::class)->process($operation);
        $this->assertSame(OperationStatus::COMPLETED, $operation->fresh()->status, $operation->fresh()->last_message);
        $this->assertNull($order->fresh()->invoice_id);
    }

    public function test_preserved_title_charges_are_not_cancelled(): void
    {
        [$invoice, , $owner] = $this->confirmed(false);
        $document = $this->document($invoice, NfeStatus::CANCELED);
        $service = app(AccountReceivableService::class);
        $title = $service->create(['company_id' => $invoice->company_id, 'customer_id' => $invoice->customer_id, 'due_date' => today()->toDateString(), 'due_amount' => 1000, 'financial_category_id' => FinancialCategory::where('company_id', $invoice->company_id)->sole()->id], $owner->id);
        app(InvoiceFinancialLinkService::class)->attachExisting($invoice, $title, $owner->id, $document->id);
        $slip = $this->slip($invoice);
        $operation = $this->request($invoice, $owner);
        app(ReverseServiceInvoiceWorkflow::class)->process($operation);
        $this->assertSame(OperationStatus::COMPLETED, $operation->fresh()->status, $operation->fresh()->last_message);
        $this->assertSame('registered', $slip->fresh()->status->value);
        $this->assertNull($title->fresh()->invoice_id);
    }

    public function test_only_administrator_with_company_membership_can_request_reversal(): void
    {
        [$invoice] = $this->confirmed();
        $foreignAdmin = User::factory()->create(['is_admin' => true]);
        $this->expectException(HttpException::class);
        $this->request($invoice, $foreignAdmin);
    }

    public function test_legacy_without_confirmation_and_unknown_financial_origin_are_blocked(): void
    {
        [$invoice, , $owner] = $this->confirmed(false);
        $title = $invoice->accountReceivables()->create(['company_id' => $invoice->company_id, 'customer_id' => $invoice->customer_id, 'due_date' => today(), 'due_amount' => 1000, 'paid_amount' => 0, 'status' => 'pending']);
        $this->expectException(ValidationException::class);
        $this->request($invoice, $owner);
    }

    public function test_request_id_and_active_slot_make_submission_idempotent(): void
    {
        [$invoice, , $owner] = $this->confirmed();
        $workflow = app(ReverseServiceInvoiceWorkflow::class);
        $id = (string) Str::uuid();
        $first = $workflow->request($invoice, $owner, 'Reverter faturamento para nova composição.', $id);
        $this->assertSame($first->id, $workflow->request($invoice, $owner, 'Reverter faturamento para nova composição.', $id)->id);
        $this->assertSame($first->id, $this->request($invoice, $owner)->id);
        $this->assertSame(1, InvoiceOperation::count());
    }

    public function test_reversal_is_integral_for_multiple_orders_and_does_not_create_new_invoice(): void
    {
        [$invoice, , $owner] = $this->confirmed(true, 2);
        $ids = $invoice->serviceOrders()->pluck('id');
        $operation = $this->request($invoice, $owner);
        app(ReverseServiceInvoiceWorkflow::class)->process($operation);
        $this->assertSame(OperationStatus::COMPLETED, $operation->fresh()->status, $operation->fresh()->last_message);
        $this->assertSame(2000.0, $invoice->fresh()->total_amount);
        $this->assertSame(2, ServiceOrder::whereIn('id', $ids)->whereNull('invoice_id')->where('status', State::CLOSED)->count());
        $this->assertSame(1, Invoice::count());
    }

    public function test_admin_panel_action_records_reversal_before_releasing_any_order(): void
    {
        [$invoice, $order, $owner] = $this->confirmed();
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($invoice->company);
        Bus::fake([ProcessInvoiceReversalJob::class]);
        Livewire::test(EditInvoice::class, ['record' => $invoice->id])
            ->assertActionVisible('reverseInvoiceBilling')
            ->callAction('reverseInvoiceBilling', data: ['request_id' => (string) Str::uuid(), 'justification' => 'Reverter faturamento integralmente pela administração.'])
            ->assertHasNoActionErrors();
        $this->assertSame(OperationStatus::REQUESTED, $invoice->operations()->sole()->status);
        $this->assertSame($invoice->id, $order->fresh()->invoice_id);
        Bus::assertDispatched(ProcessInvoiceReversalJob::class);
    }

    public function test_old_confirmed_invoice_without_snapshot_is_not_reconstructed_or_reversed_implicitly(): void
    {
        [$invoice, , $owner] = $this->confirmed(false);
        $legacy = $invoice->replicate();
        $legacy->invoice_number = 'LEGACY-001';
        $legacy->save();
        try {
            $this->request($legacy, $owner);
            $this->fail('Uma fatura legada sem histórico exige revisão.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('histórica', implode(' ', $exception->errors()['invoice']));
            $this->assertSame(0, InvoiceOperation::count());
        }
    }

    public function test_products_and_production_scope_is_rejected_before_any_cancellation_or_stock_effect(): void
    {
        [$invoice, $order, $owner] = $this->confirmed(false);
        FiscalDocument::create(['company_id' => $invoice->company_id, 'customer_id' => $invoice->customer_id, 'invoice_id' => $invoice->id, 'document_type' => 'nfe', 'status' => FiscalStatus::CONFIRMED, 'nfe_status' => NfeStatus::AUTHORIZED, 'issued_at' => today(), 'movement_at' => today()]);
        $before = StockMovement::count();
        try {
            $this->request($invoice, $owner);
            $this->fail('Produtos devem aguardar a etapa de estoque.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Produtos e estoque', implode(' ', $exception->errors()['invoice']));
            $this->assertSame($before, StockMovement::count());
            $this->assertSame($invoice->id, $order->fresh()->invoice_id);
        }
    }

    public function test_fiscal_callback_does_not_create_new_titles_while_reversal_is_active(): void
    {
        [$invoice, , $owner] = $this->confirmed(false);
        $document = $this->document($invoice, NfeStatus::AUTHORIZED);
        $this->request($invoice, $owner);
        $generation = app(AccountReceivableGenerationService::class);
        $this->assertFalse($generation->generateFromFiscalDocument($document));
        $this->assertSame(0, $invoice->accountReceivables()->count());
    }

    public function test_rejected_nfse_without_authorization_is_archived_locally_without_requesting_a_fiscal_cancellation(): void
    {
        [$invoice, $order, $owner] = $this->confirmed(false);
        $document = $this->document($invoice, NfeStatus::REJECTED);
        $document->update(['status' => FiscalStatus::PENDING, 'authorized_at' => null]);
        $this->mock(NfseDocumentService::class)->shouldNotReceive('cancelar');
        $operation = $this->request($invoice, $owner);
        app(ReverseServiceInvoiceWorkflow::class)->process($operation);
        $this->assertSame(OperationStatus::COMPLETED, $operation->fresh()->status, $operation->fresh()->last_message);
        $this->assertSame(FiscalStatus::CANCELLED, $document->fresh()->status);
        $this->assertSame(NfeStatus::REJECTED, $document->fresh()->nfse_status);
        $this->assertNull($order->fresh()->invoice_id);
    }

    public function test_revoked_administrator_during_cancellation_cannot_release_orders_and_another_admin_can_resume(): void
    {
        [$invoice, $order, $owner] = $this->confirmed(false);
        $document = $this->document($invoice, NfeStatus::AUTHORIZED);
        $operation = $this->request($invoice, $owner);
        $mock = $this->mock(NfseDocumentService::class);
        $mock->shouldReceive('cancelar')->once()->andReturnUsing(function ($document) use ($owner, $invoice): bool {
            $document->update(['status' => FiscalStatus::CANCELLED, 'nfse_status' => NfeStatus::CANCELED]);
            $owner->companies()->updateExistingPivot($invoice->company_id, ['is_active' => false]);

            return true;
        });
        $workflow = app(ReverseServiceInvoiceWorkflow::class);
        $workflow->process($operation);
        $this->assertSame(OperationStatus::NEEDS_INTERVENTION, $operation->fresh()->status);
        $this->assertSame($invoice->id, $order->fresh()->invoice_id);
        $this->assertTrue($document->fresh()->isFiscalCancellationConfirmed());
        $replacement = User::factory()->create();
        $replacement->companies()->attach($invoice->company_id, ['role' => 'owner', 'is_active' => true]);
        $resumed = $this->request($invoice, $replacement);
        $workflow->process($resumed);
        $this->assertSame(OperationStatus::COMPLETED, $resumed->fresh()->status, $resumed->fresh()->last_message);
        $this->assertSame($owner->id, $resumed->fresh()->requested_by);
        $this->assertSame($replacement->id, $invoice->fresh()->canceled_by);
        $this->assertNull($order->fresh()->invoice_id);
    }

    private function confirmed(bool $generateReceivable = true, int $orderCount = 1): array
    {
        $order = ServiceOrder::factory()->create(['status' => State::CLOSED]);
        $owner = User::findOrFail($order->created_by);
        $owner->companies()->attach($order->company_id, ['role' => 'owner', 'is_active' => true]);
        $order->customer->companies()->attach($order->company_id, ['is_active' => true, 'type' => '["customer"]']);
        $invoice = Invoice::create(['company_id' => $order->company_id, 'customer_id' => $order->customer_id, 'invoice_number' => 'REVERSE-001', 'invoice_date' => today(), 'status' => Status::PENDING, 'financial_policy' => $generateReceivable ? null : 'fiscal_only', 'created_by' => $owner->id]);
        $order->update(['invoice_id' => $invoice->id, 'status' => State::INVOICED]);
        $service = Service::factory()->create(['company_id' => $invoice->company_id]);
        ServiceOrderItem::create(['service_order_id' => $order->id, 'service_id' => $service->id, 'quantity' => 1, 'unit_price' => 1000, 'discount_amount' => 0, 'created_by' => $owner->id]);
        for ($i = 1; $i < $orderCount; $i++) {
            $other = ServiceOrder::factory()->create(['company_id' => $invoice->company_id, 'customer_id' => $invoice->customer_id, 'invoice_id' => $invoice->id, 'status' => State::INVOICED, 'created_by' => $owner->id]);
            ServiceOrderItem::create(['service_order_id' => $other->id, 'service_id' => $service->id, 'quantity' => 1, 'unit_price' => 1000, 'discount_amount' => 0, 'created_by' => $owner->id]);
        }
        $category = FinancialCategory::create(['company_id' => $invoice->company_id, 'name' => 'Receita', 'is_active' => true, 'allow_receivable' => true, 'allow_cash_movement' => true]);
        $action = new ConfirmInvoiceAction($invoice, $owner->id);
        $this->assertNotNull($action->execute(['payment_method' => Method::PIX->value, 'payment_condition' => Condition::CASH->value, 'financial_category_id' => $category->id], false), (string) $action->getMessage());

        return [$invoice->fresh(), $order, $owner];
    }

    private function request(Invoice $invoice, User $user): InvoiceOperation
    {
        return app(ReverseServiceInvoiceWorkflow::class)->request($invoice, $user, 'Reverter faturamento para nova composição.', (string) Str::uuid());
    }

    private function document(Invoice $invoice, NfeStatus $state): FiscalDocument
    {
        return FiscalDocument::create(['company_id' => $invoice->company_id, 'customer_id' => $invoice->customer_id, 'invoice_id' => $invoice->id, 'document_type' => 'nfse', 'status' => $state === NfeStatus::CANCELED ? FiscalStatus::CANCELLED : FiscalStatus::CONFIRMED, 'nfse_status' => $state, 'issued_at' => today(), 'movement_at' => today(), 'nfse_model' => 'municipal', 'document_key' => (string) Str::uuid()]);
    }

    private function slip(Invoice $invoice): BankSlip
    {
        $account = FinancialAccount::create(['company_id' => $invoice->company_id, 'name' => 'Banco', 'type' => 'bank', 'is_active' => true]);
        $bank = Bank::firstOrCreate(['code' => '461'], ['name' => 'Asaas']);
        $connection = BankAccountConnection::create(['company_id' => $invoice->company_id, 'financial_account_id' => $account->id, 'bank_id' => $bank->id, 'billing_provider_id' => BillingProvider::where('key', 'integrabancos')->firstOrFail()->id, 'environment' => 'sandbox', 'status' => 'active', 'credentials' => ['access_token' => 'test', 'secret_key' => str_repeat('k', 32), 'x_api_key' => 'test']]);

        return BankSlip::create(['company_id' => $invoice->company_id, 'account_receivable_installment_id' => $invoice->accountReceivables()->sole()->installments()->firstOrFail()->id, 'bank_account_connection_id' => $connection->id, 'financial_account_id' => $account->id, 'status' => 'registered', 'operation_status' => 'confirmed', 'provider_identification' => 'TEST-001', 'request_sent_at' => now(), 'amount' => 1000, 'due_date' => today()]);
    }
}
