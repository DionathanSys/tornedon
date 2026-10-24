<?php

namespace Tests\Feature\Services\Payment;

use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\OperationType;
use App\Enum\Invoice\Status;
use App\Enum\Payment\Condition;
use App\Enum\Payment\Method;
use App\Enum\ServiceOrder\Priority;
use App\Enum\ServiceOrder\State;
use App\Enum\ServiceOrder\Type;
use App\Filament\Clusters\Financial\Resources\Invoices\Pages\EditInvoice;
use App\Models\Company;
use App\Models\FinancialCategory;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentItem;
use App\Models\Invoice;
use App\Models\Partner;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderItem;
use App\Models\User;
use App\Services\AccountPayable\AccountPayableService;
use App\Services\FiscalDocument\Actions\GenerateFiscalEntryPayableAction;
use App\Services\Invoice\InvoiceService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomPaymentTermsTest extends TestCase
{
    use RefreshDatabase;

    public static function generationFlows(): array
    {
        return ['confirmation' => [false], 'manual generation' => [true]];
    }

    #[DataProvider('generationFlows')]
    public function test_invoice_generates_long_custom_schedule_and_preserves_total(bool $confirmed): void
    {
        [$user, $company, $invoice, $category] = $this->context($confirmed);
        $details = ['installments' => 37, 'first_due_days' => 17, 'interval_days' => 45];
        $data = [
            'payment_method' => Method::BANK_SLIP->value,
            'payment_condition' => Condition::CUSTOM->value,
            'payment_condition_details' => $details,
            'financial_category_id' => $category->id,
        ];
        $service = app(InvoiceService::class);
        $result = $confirmed
            ? $service->generateAccountReceivables($invoice, $data, $user->id)
            : $service->confirm($invoice, $data, $user->id, generateFiscalDocuments: false);

        $this->assertNotNull($result, $service->getMessage());
        $this->assertSame($details, $invoice->fresh()->payment_condition_details);
        $installments = $invoice->installments()->orderBy('due_date')->get();
        $this->assertCount(37, $installments);
        $this->assertSame('2026-01-27', $installments->first()->due_date->toDateString());
        $this->assertSame('2026-03-13', $installments[1]->due_date->toDateString());
        $this->assertSame('2030-07-05', $installments->last()->due_date->toDateString());
        $this->assertSame(100001, $installments->sum(fn ($item) => (int) round($item->due_amount * 100)));
        $this->assertSame(0.0, (float) $installments->sum('paid_amount'));
        $this->assertSame($company->id, $installments->last()->company_id);
    }

    public function test_saved_custom_schedule_is_reused_by_manual_generation(): void
    {
        [$user, , $invoice, $category] = $this->context(true);
        $invoice->update([
            'payment_condition' => Condition::CUSTOM,
            'payment_condition_details' => ['installments' => 2, 'first_due_days' => 0, 'interval_days' => 7],
        ]);
        $service = app(InvoiceService::class);
        $result = $service->generateAccountReceivables($invoice->fresh(), [
            'payment_method' => Method::PIX->value,
            'payment_condition' => Condition::CUSTOM->value,
            'financial_category_id' => $category->id,
        ], $user->id);

        $this->assertNotNull($result, $service->getMessage());
        $this->assertSame(['2026-01-10', '2026-01-17'], $invoice->installments()->orderBy('due_date')->get()->map(fn ($item) => $item->due_date->toDateString())->all());
    }

    public function test_custom_without_configuration_cannot_confirm_or_create_financial_records(): void
    {
        [$user, , $invoice, $category] = $this->context();
        $service = app(InvoiceService::class);
        $result = $service->confirm($invoice, [
            'payment_method' => Method::PIX->value,
            'payment_condition' => Condition::CUSTOM->value,
            'financial_category_id' => $category->id,
        ], $user->id, generateFiscalDocuments: false);

        $this->assertNull($result);
        $this->assertFalse($invoice->fresh()->confirmed);
        $this->assertSame(0, $invoice->accountReceivables()->count());
    }

    public function test_long_preset_generates_sixty_installments(): void
    {
        [$user, , $invoice, $category] = $this->context(true);
        $service = app(InvoiceService::class);
        $result = $service->generateAccountReceivables($invoice, [
            'payment_method' => Method::BANK_SLIP->value,
            'payment_condition' => Condition::INSTALLMENTS_60X->value,
            'financial_category_id' => $category->id,
        ], $user->id);

        $this->assertNotNull($result, $service->getMessage());
        $this->assertCount(60, $invoice->installments);
        $this->assertSame('2026-02-09', $invoice->installments()->orderBy('due_date')->first()->due_date->toDateString());
    }

    public function test_payable_supports_more_than_twenty_four_installments(): void
    {
        [$user, $company, $invoice] = $this->context();
        $category = FinancialCategory::create([
            'company_id' => $company->id, 'name' => 'Compras', 'allow_payable' => true, 'is_active' => true,
        ]);
        $service = app(AccountPayableService::class);
        $payable = $service->create([
            'company_id' => $company->id, 'supplier_id' => $invoice->customer_id,
            'due_date' => '2026-01-10', 'due_amount' => 1000.01, 'installment_count' => 48,
            'payment_method' => Method::BANK_SLIP->value, 'financial_category_id' => $category->id,
        ], $user->id);

        $this->assertNotNull($payable, $service->getMessage());
        $this->assertCount(48, $payable->installments);
        $this->assertSame(100001, $payable->installments->sum(fn ($item) => (int) round($item->due_amount * 100)));
    }

    public function test_confirmation_modal_shows_and_requires_custom_fields(): void
    {
        [$user, $company, $invoice] = $this->context();
        $user->companies()->attach($company, ['role' => 'admin', 'is_active' => true]);
        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
        Filament::setTenant($company);

        Livewire::test(EditInvoice::class, ['record' => (string) $invoice->getRouteKey()])
            ->mountAction('confirmInvoice')
            ->setActionData(['payment_condition' => Condition::CUSTOM->value, 'payment_method' => Method::PIX->value])
            ->assertMountedActionModalSee('Quantidade de parcelas')
            ->assertMountedActionModalSee('Primeiro vencimento em (dias)')
            ->assertMountedActionModalSee('Intervalo entre parcelas (dias)')
            ->callMountedAction()
            ->assertHasActionErrors([
                'payment_condition_details.installments' => 'required',
                'payment_condition_details.first_due_days' => 'required',
                'payment_condition_details.interval_days' => 'required',
            ]);
    }

    public function test_fiscal_entry_uses_custom_first_due_date_and_interval(): void
    {
        [$user, $company, $invoice] = $this->context();
        $document = FiscalDocument::create([
            'company_id' => $company->id, 'customer_id' => $invoice->customer_id,
            'document_type' => DocumentModel::NFE,
            'status' => \App\Enum\FiscalDocument\Status::CONFIRMED,
            'operation_type' => OperationType::ENTRADA,
            'issued_at' => '2026-01-10', 'movement_at' => '2026-01-10', 'document_number' => 'NF-TERMS', 'created_by' => $user->id,
        ]);
        FiscalDocumentItem::create([
            'fiscal_document_id' => $document->id, 'item_number' => 1, 'product_code' => 'TERMS',
            'description' => 'Compra', 'quantity' => 1, 'unit_of_measure' => 'UN',
            'unit_price' => 1000.01, 'total_price' => 1000.01, 'created_by' => $user->id,
        ]);
        $result = app(GenerateFiscalEntryPayableAction::class)->execute($document, [
            'payment_method' => Method::BANK_SLIP->value, 'payment_condition' => Condition::CUSTOM->value,
            'payment_condition_details' => ['installments' => 36, 'first_due_days' => 12, 'interval_days' => 15],
        ], $user->id);

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['payables']);
        $installments = $document->accountPayables()->firstOrFail()->installments()->orderBy('due_date')->get();
        $this->assertCount(36, $installments);
        $this->assertSame('2026-01-22', $installments->first()->due_date->toDateString());
        $this->assertSame('2026-02-06', $installments[1]->due_date->toDateString());
    }

    private function context(bool $confirmed = false): array
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Prazos', 'address' => ['city' => 'São Paulo', 'state' => 'SP'], 'created_by' => $user->id]);
        $customer = Partner::create(['name' => 'Cliente', 'document_type' => 'CPF', 'document_number' => '12345678901', 'created_by' => $user->id]);
        $customer->companies()->attach($company, ['type' => '["customer"]', 'is_active' => true]);
        $category = FinancialCategory::create(['company_id' => $company->id, 'name' => 'Vendas', 'is_active' => true, 'allow_receivable' => true]);
        $invoice = Invoice::create([
            'company_id' => $company->id, 'customer_id' => $customer->id, 'invoice_number' => 'TERMS-001',
            'invoice_date' => '2026-01-10', 'status' => $confirmed ? Status::CONFIRMED : Status::PENDING,
            'confirmed' => $confirmed, 'pending' => ! $confirmed, 'canceled' => false, 'created_by' => $user->id,
        ]);
        $service = Service::create(['company_id' => $company->id, 'name' => 'Serviço', 'service_code' => 'TERMS', 'price' => 1000.01, 'created_by' => $user->id]);
        $order = ServiceOrder::create([
            'company_id' => $company->id, 'customer_id' => $customer->id, 'invoice_id' => $invoice->id,
            'number' => 'SO-TERMS', 'order_date' => '2026-01-10', 'status' => State::CLOSED,
            'priority' => Priority::NORMAL,
            'type' => Type::MAINTENANCE, 'created_by' => $user->id,
        ]);
        ServiceOrderItem::create(['service_order_id' => $order->id, 'service_id' => $service->id, 'quantity' => 1, 'unit_price' => 1000.01, 'created_by' => $user->id]);

        return [$user, $company, $invoice, $category];
    }
}
