<?php

namespace Tests\Feature\Services\AccountReceivable;

use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\NfeStatus;
use App\Enum\FiscalDocument\Status as FiscalDocumentStatus;
use App\Enum\Invoice\Status as InvoiceStatus;
use App\Enum\Payment\Condition as PaymentCondition;
use App\Enum\Payment\Method as PaymentMethod;
use App\Enum\ServiceOrder\Priority as ServiceOrderPriority;
use App\Enum\ServiceOrder\State as ServiceOrderState;
use App\Enum\ServiceOrder\Type as ServiceOrderType;
use App\Models\AccountReceivable;
use App\Models\Company;
use App\Models\FiscalDocument;
use App\Models\Invoice;
use App\Models\Partner;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\AccountReceivable\AccountReceivableGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountReceivableGenerationServiceTest extends TestCase
{
    use RefreshDatabase;

    private AccountReceivableGenerationService $service;
    private User $user;
    private Company $company;
    private Partner $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(AccountReceivableGenerationService::class);
        $this->user = User::factory()->create();

        $this->company = Company::create([
            'name' => 'Empresa Teste ' . uniqid(),
            'document_number' => '123456780001' . random_int(10, 99),
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $this->user->id,
        ]);

        $this->customer = Partner::create([
            'name' => 'Cliente Teste ' . uniqid(),
            'document_type' => 'CPF',
            'document_number' => '123456789' . random_int(10, 99),
            'created_by' => $this->user->id,
        ]);
    }

    public function test_generates_header_and_installments_with_invoice_payment_method_and_condition(): void
    {
        $invoice = $this->createInvoice('2026-03-10');

        ServiceOrder::create([
            'number' => 'OS-' . uniqid(),
            'customer_id' => $this->customer->id,
            'company_id' => $this->company->id,
            'order_date' => '2026-03-10',
            'status' => ServiceOrderState::INVOICED->value,
            'priority' => ServiceOrderPriority::NORMAL->value,
            'type' => ServiceOrderType::MAINTENANCE->value,
            'travel_value' => 100.00,
            'payment_method' => PaymentMethod::PIX->value,
            'payment_condition' => PaymentCondition::DAYS_30_60_90->value,
            'invoice_id' => $invoice->id,
        ]);

        $fiscalDocument = $this->createAuthorizedNfeForInvoice($invoice);

        $ok = $this->service->generateFromFiscalDocument($fiscalDocument);

        $this->assertTrue($ok, $this->service->getMessage());
        $this->assertDatabaseCount('account_receivables', 1);
        $accountReceivable = AccountReceivable::query()->with('installments')->sole();

        $this->assertSame($invoice->id, $accountReceivable->invoice_id);
        $this->assertSame($fiscalDocument->id, $accountReceivable->fiscal_document_id);
        $this->assertSame(PaymentMethod::PIX, $accountReceivable->payment_method);
        $this->assertSame(100.0, $accountReceivable->due_amount);
        $this->assertSame('2026-04-09', $accountReceivable->due_date?->toDateString());
        $this->assertDatabaseCount('account_receivable_installments', 3);
        $this->assertSame(
            [
                ['sequence_number' => '01', 'due_amount' => 33.33, 'due_date' => '2026-04-09'],
                ['sequence_number' => '02', 'due_amount' => 33.33, 'due_date' => '2026-05-09'],
                ['sequence_number' => '03', 'due_amount' => 33.34, 'due_date' => '2026-06-08'],
            ],
            $accountReceivable->installments
                ->sortBy('sequence_number')
                ->values()
                ->map(fn ($installment) => [
                    'sequence_number' => $installment->sequence_number,
                    'due_amount' => $installment->due_amount,
                    'due_date' => $installment->due_date?->toDateString(),
                ])
                ->all()
        );
    }

    public function test_reprocessing_does_not_duplicate_receivable_header_or_installments(): void
    {
        $invoice = $this->createInvoice('2026-03-10');

        ServiceOrder::create([
            'number' => 'OS-' . uniqid(),
            'customer_id' => $this->customer->id,
            'company_id' => $this->company->id,
            'order_date' => '2026-03-10',
            'status' => ServiceOrderState::INVOICED->value,
            'priority' => ServiceOrderPriority::NORMAL->value,
            'type' => ServiceOrderType::MAINTENANCE->value,
            'travel_value' => 120.00,
            'payment_method' => PaymentMethod::BANK_SLIP->value,
            'payment_condition' => PaymentCondition::INSTALLMENTS_2X->value,
            'invoice_id' => $invoice->id,
        ]);

        $fiscalDocument = $this->createAuthorizedNfeForInvoice($invoice);

        $this->assertTrue($this->service->generateFromFiscalDocument($fiscalDocument));
        $this->assertTrue($this->service->generateFromFiscalDocument($fiscalDocument));

        $this->assertDatabaseCount('account_receivables', 1);
        $accountReceivable = AccountReceivable::query()->with('installments')->sole();

        $this->assertDatabaseCount('account_receivable_installments', 2);
        $this->assertSame(
            [
                ['sequence_number' => '01', 'due_amount' => 60.0],
                ['sequence_number' => '02', 'due_amount' => 60.0],
            ],
            $accountReceivable->installments
                ->sortBy('sequence_number')
                ->values()
                ->map(fn ($installment) => [
                    'sequence_number' => $installment->sequence_number,
                    'due_amount' => $installment->due_amount,
                ])
                ->all()
        );
    }

    public function test_blocks_generation_when_payment_method_is_missing(): void
    {
        $invoice = $this->createInvoice('2026-03-10');

        ServiceOrder::create([
            'number' => 'OS-' . uniqid(),
            'customer_id' => $this->customer->id,
            'company_id' => $this->company->id,
            'order_date' => '2026-03-10',
            'status' => ServiceOrderState::INVOICED->value,
            'priority' => ServiceOrderPriority::NORMAL->value,
            'type' => ServiceOrderType::MAINTENANCE->value,
            'travel_value' => 80.00,
            'payment_condition' => PaymentCondition::CASH->value,
            'invoice_id' => $invoice->id,
        ]);

        $fiscalDocument = $this->createAuthorizedNfeForInvoice($invoice);

        $ok = $this->service->generateFromFiscalDocument($fiscalDocument);

        $this->assertFalse($ok);
        $this->assertDatabaseCount('account_receivables', 0);
        $this->assertDatabaseCount('account_receivable_installments', 0);
    }

    private function createInvoice(string $invoiceDate): Invoice
    {
        return Invoice::create([
            'customer_id' => $this->customer->id,
            'company_id' => $this->company->id,
            'invoice_number' => (string) random_int(100000, 999999),
            'invoice_date' => $invoiceDate,
            'status' => InvoiceStatus::CONFIRMED->value,
            'pending' => false,
            'confirmed' => true,
            'created_by' => $this->user->id,
        ]);
    }

    private function createAuthorizedNfeForInvoice(Invoice $invoice): FiscalDocument
    {
        return FiscalDocument::create([
            'customer_id' => $invoice->customer_id,
            'company_id' => $invoice->company_id,
            'invoice_id' => $invoice->id,
            'status' => FiscalDocumentStatus::CONFIRMED->value,
            'issued_at' => $invoice->invoice_date,
            'movement_at' => $invoice->invoice_date,
            'document_type' => DocumentModel::NFE->value,
            'document_number' => 'NF-' . random_int(1000, 9999),
            'document_series' => '1',
            'nfe_status' => NfeStatus::AUTHORIZED->value,
        ]);
    }
}
