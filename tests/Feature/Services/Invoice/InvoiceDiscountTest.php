<?php

namespace Tests\Feature\Services\Invoice;

use App\Enum\Invoice\Status;
use App\Enum\Payment\Condition;
use App\Enum\Payment\Method;
use App\Enum\ServiceOrder\State;
use App\Filament\Clusters\Financial\Resources\Invoices\Pages\EditInvoice;
use App\Filament\Clusters\Financial\Resources\Invoices\Widgets\InvoicesStatsOverview;
use App\Filament\Pages\ProductProfitReport;
use App\Models\FinancialCategory;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderItem;
use App\Models\User;
use App\Services\AccountReceivable\AccountReceivableService;
use App\Services\Invoice\Actions\ConfirmInvoiceAction;
use App\Services\Invoice\InvoiceDiscountService;
use App\Services\Invoice\InvoiceDraftCompositionService;
use App\Services\Invoice\InvoiceService;
use App\Services\Invoice\Support\InvoicePdfDataFormatter;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class InvoiceDiscountTest extends TestCase
{
    use RefreshDatabase;

    public function test_discount_preserves_sale_values_and_travel_in_all_invoice_total_paths(): void
    {
        [$invoice, $order, $item] = $this->draft();
        app(InvoiceDiscountService::class)->apply($invoice, 90, ['service_order:'.$item->id => 100], 'Negociação', $invoice->created_by);
        $this->assertSame(100.0, $item->fresh()->discount_amount);
        $this->assertSame(900.0, $item->fresh()->total_amount);
        $this->assertSame(810.0, $item->fresh()->invoiced_net_amount);
        $this->assertSame(860.0, $order->fresh()->invoiced_net_amount);
        $this->assertSame(860.0, $invoice->fresh()->total_amount);
        $this->assertSame(190.0, $invoice->fresh()->discount_amount);
        $this->assertSame(860.0, $invoice->fresh()->load('serviceOrders.items')->total_amount);
        $invoices = Invoice::whereKey($invoice->id)->get();
        Invoice::preloadAmounts($invoices);
        $this->assertSame(860.0, $invoices->first()->net_value);
    }

    public function test_return_to_pending_keeps_discount_and_reconfirmation_does_not_duplicate_it(): void
    {
        [$invoice, $order, $item] = $this->draft();
        app(InvoiceDiscountService::class)->apply($invoice, 90, ['service_order:'.$item->id => 100], 'Fechamento', $invoice->created_by);
        $category = FinancialCategory::create(['company_id' => $invoice->company_id, 'name' => 'Receita', 'is_active' => true, 'allow_receivable' => true]);
        $terms = ['payment_method' => Method::PIX->value, 'payment_condition' => Condition::CASH->value, 'financial_category_id' => $category->id];
        $confirm = new ConfirmInvoiceAction($invoice, $invoice->created_by);
        $this->assertNotNull($confirm->execute($terms, false), (string) $confirm->getMessage());
        $this->assertSame(860.0, (float) $invoice->fresh()->accountReceivables()->first()->due_amount);
        $this->assertSame(86000, $invoice->fresh()->latestConfirmation->amounts['net_value']);
        $snapshot = $invoice->fresh()->latestConfirmation->sources->first()->snapshot;
        $this->assertSame(9000, $snapshot['items'][0]['invoice_discount_amount']);
        $this->assertSame(10000, $snapshot['items'][0]['discount_amount']);

        $service = app(InvoiceService::class);
        $this->assertTrue($service->returnToPending($invoice->fresh(), $invoice->created_by), (string) $service->getMessage());
        $this->assertSame(90.0, $item->fresh()->invoice_discount_amount);
        $confirm = new ConfirmInvoiceAction($invoice->fresh(), $invoice->created_by);
        $this->assertNotNull($confirm->execute($terms, false), (string) $confirm->getMessage());
        $this->assertSame(860.0, $invoice->fresh()->net_value);
        $this->assertSame(2, $invoice->confirmations()->count());
        $pdf = app(InvoicePdfDataFormatter::class)->format($invoice->fresh());
        $this->assertSame('R$ 860,00', $pdf['service_orders'][0]['total']);
    }

    public function test_confirmed_invoice_rejects_discount_action_and_direct_item_mutation(): void
    {
        [$invoice, , $item] = $this->draft();
        $invoice->update(['financial_policy' => 'fiscal_only']);
        $confirm = new ConfirmInvoiceAction($invoice, $invoice->created_by);
        $this->assertNotNull($confirm->execute(['payment_method' => Method::PIX->value, 'payment_condition' => Condition::CASH->value], false));
        try {
            app(InvoiceDiscountService::class)->apply($invoice, 90, ['service_order:'.$item->id => 100], null, $invoice->created_by);
            $this->fail('Desconto em confirmada deve ser bloqueado.');
        } catch (ValidationException) {
            $this->assertSame(0.0, $item->fresh()->invoice_discount_amount);
        }
        $this->expectException(ValidationException::class);
        $item->update(['invoice_discount_amount' => 90]);
    }

    public function test_changed_composition_requires_review_before_confirmation(): void
    {
        [$invoice, , $item] = $this->draft();
        $service = app(InvoiceDiscountService::class);
        $service->apply($invoice, 90, ['service_order:'.$item->id => 100], null, $invoice->created_by);
        $item->refresh()->update(['unit_price' => 1200]);
        $confirm = new ConfirmInvoiceAction($invoice, $invoice->created_by);
        $this->assertNull($confirm->execute(['payment_method' => Method::PIX->value, 'payment_condition' => Condition::CASH->value], false));
        $this->assertStringContainsString('composição mudou', json_encode($confirm->getErrors(), JSON_UNESCAPED_UNICODE));
        $service->apply($invoice, 90, ['service_order:'.$item->id => 100], null, $invoice->created_by);
        $service->ensureValid($invoice);
        $this->assertSame(1060.0, $invoice->fresh()->net_value);
    }

    public function test_releasing_source_clears_its_discount_and_requires_redistribution(): void
    {
        [$invoice, $order, $item] = $this->draft();
        app(InvoiceDiscountService::class)->apply($invoice, 90, ['service_order:'.$item->id => 100], null, $invoice->created_by);
        app(InvoiceDraftCompositionService::class)->remove($invoice, $order, $invoice->created_by);
        $this->assertSame(0.0, $item->fresh()->invoice_discount_amount);
        $this->assertSame(100.0, $item->fresh()->discount_amount);
        $this->expectException(ValidationException::class);
        app(InvoiceDiscountService::class)->ensureValid($invoice->fresh());
    }

    public function test_invalid_shares_or_item_limit_roll_back_the_entire_distribution(): void
    {
        [$invoice, $order, $item] = $this->draft();
        $second = ServiceOrderItem::create(['service_order_id' => $order->id, 'service_id' => $item->service_id, 'quantity' => 1, 'unit_price' => 10, 'created_by' => $invoice->created_by]);
        $service = app(InvoiceDiscountService::class);
        foreach ([[50, 20], [0, 100], [-10, 110]] as [$firstShare, $secondShare]) {
            try {
                $service->apply($invoice, 90, ['service_order:'.$item->id => $firstShare, 'service_order:'.$second->id => $secondShare], null, $invoice->created_by);
                $this->fail('Distribuição inválida aceita.');
            } catch (ValidationException) {
                $this->assertSame(0.0, $item->fresh()->invoice_discount_amount);
                $this->assertSame(0.0, $invoice->fresh()->invoice_discount_amount);
            }
        }
    }

    public function test_rounding_preserves_exact_cents_with_editable_shares(): void
    {
        $service = app(InvoiceDiscountService::class);
        $this->assertSame(['a' => 34, 'b' => 34, 'c' => 33], $service->allocate(101, ['a' => 1, 'b' => 1, 'c' => 1]));
        [$invoice, $order, $item] = $this->draft();
        $second = ServiceOrderItem::create(['service_order_id' => $order->id, 'service_id' => $item->service_id, 'quantity' => 1, 'unit_price' => 100, 'created_by' => $invoice->created_by]);
        $service->apply($invoice, 1.01, ['service_order:'.$item->id => 50, 'service_order:'.$second->id => 50], null, $invoice->created_by);
        $this->assertSame(51, $item->fresh()->getRawOriginal('invoice_discount_amount'));
        $this->assertSame(50, $second->fresh()->getRawOriginal('invoice_discount_amount'));
        $service->ensureValid($invoice);
    }

    public function test_cross_company_actor_cannot_apply_discount(): void
    {
        [$invoice, , $item] = $this->draft();
        $this->expectException(AuthorizationException::class);
        app(InvoiceDiscountService::class)->apply($invoice, 90, ['service_order:'.$item->id => 100], null, User::factory()->create()->id);
    }

    public function test_removing_discount_restores_original_total_and_clears_shares(): void
    {
        [$invoice, , $item] = $this->draft();
        $service = app(InvoiceDiscountService::class);
        $service->apply($invoice, 90, ['service_order:'.$item->id => 100], null, $invoice->created_by);
        $service->apply($invoice, 0, [], null, $invoice->created_by);
        $this->assertSame(950.0, $invoice->fresh()->net_value);
        $this->assertSame(0.0, $item->fresh()->invoice_discount_amount);
        $this->assertSame('0.000000', $item->fresh()->invoice_discount_share_percentage);
        $this->assertNull($invoice->fresh()->invoice_discount_signature);
        $service->ensureValid($invoice);
    }

    public function test_stale_composition_cannot_be_applied_from_an_open_distribution_form(): void
    {
        [$invoice, , $item] = $this->draft();
        $service = app(InvoiceDiscountService::class);
        $signature = $service->signature($service->rows($invoice));
        $item->update(['unit_price' => 1100]);
        $this->expectException(ValidationException::class);
        $service->apply($invoice, 90, ['service_order:'.$item->id => 100], null, $invoice->created_by, $signature);
    }

    public function test_filament_action_distributes_percentage_without_discounting_travel(): void
    {
        [$invoice, $order, $item] = $this->draft();
        $this->actingAs(User::findOrFail($invoice->created_by));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($order->company);
        Livewire::test(EditInvoice::class, ['record' => $invoice->id])
            ->callAction('invoiceDiscount', data: [
                'mode' => 'percentage', 'value' => 10, 'reason' => 'Fechamento',
                'items' => [['key' => 'service_order:'.$item->id, 'label' => 'Serviço', 'base' => 90000, 'gross' => 100000, 'sale_discount' => 10000, 'share' => 100]],
            ])->assertHasNoActionErrors();
        $this->assertSame(90.0, $invoice->fresh()->invoice_discount_amount);
        $this->assertSame(860.0, $invoice->fresh()->net_value);
    }

    public function test_filament_keeps_distribution_open_and_explains_incomplete_shares(): void
    {
        [$invoice, $order, $item] = $this->draft();
        $this->actingAs(User::findOrFail($invoice->created_by));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($order->company);
        Livewire::test(EditInvoice::class, ['record' => $invoice->id])
            ->callAction('invoiceDiscount', data: [
                'mode' => 'amount', 'value' => 90,
                'items' => [['key' => 'service_order:'.$item->id, 'label' => 'Serviço', 'base' => 90000, 'gross' => 100000, 'sale_discount' => 10000, 'share' => 50]],
            ])->assertNotified('Revise o desconto de fechamento')->assertActionMounted('invoiceDiscount');
        $this->assertSame(0.0, $invoice->fresh()->invoice_discount_amount);
    }

    public function test_mixed_invoice_product_profit_and_invoice_cards_use_adjusted_values(): void
    {
        [$invoice, $order, $item] = $this->draft();
        $requisition = Requisition::create([
            'company_id' => $invoice->company_id, 'customer_id' => $invoice->customer_id, 'number' => 'REQ-DISC',
            'sale_date' => today(), 'status' => \App\Enum\Requisition\Status::INVOICED,
            'invoice_id' => $invoice->id, 'created_by' => $invoice->created_by,
        ]);
        $product = Product::factory()->create(['company_id' => $invoice->company_id]);
        $productItem = RequisitionItem::create([
            'requisition_id' => $requisition->id, 'product_id' => $product->id, 'quantity' => 1,
            'unit_of_measure' => 'UN', 'unit_price' => 200, 'discount_amount' => 20, 'unit_cost' => 100, 'created_by' => $invoice->created_by,
        ]);
        app(InvoiceDiscountService::class)->apply($invoice, 100, ['service_order:'.$item->id => 80, 'requisition:'.$productItem->id => 20], 'Mista', $invoice->created_by);
        $this->actingAs(User::findOrFail($invoice->created_by));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($order->company);

        $report = new ProductProfitReport;
        $row = $report->getRowsProperty()->sole();
        $this->assertSame(160.0, $row['sold_amount']);
        $this->assertSame(60.0, $row['profit_amount']);
        $this->assertSame(37.5, $row['margin_percent']);
        $this->assertSame(20.0, $row['sale_discount_amount']);
        $this->assertSame(20.0, $row['invoice_discount_amount']);
        $this->assertSame(180.0, $productItem->fresh()->total_amount);
        $this->assertSame(160.0, $requisition->fresh()->invoiced_net_amount);

        $widget = new InvoicesStatsOverview;
        $method = new \ReflectionMethod($widget, 'resolveTotals');
        $totals = $method->invoke($widget, Invoice::whereKey($invoice->id));
        $this->assertSame(1030.0, $totals['net_value']);
        $this->assertSame(870.0, $totals['services_value']);
        $this->assertSame(160.0, $totals['products_value']);
    }

    public function test_receipt_discount_remains_independent_of_confirmed_invoice_discount(): void
    {
        [$invoice, , $item] = $this->draft();
        app(InvoiceDiscountService::class)->apply($invoice, 90, ['service_order:'.$item->id => 100], null, $invoice->created_by);
        $category = FinancialCategory::create(['company_id' => $invoice->company_id, 'name' => 'Receita', 'is_active' => true, 'allow_receivable' => true]);
        $confirm = new ConfirmInvoiceAction($invoice, $invoice->created_by);
        $this->assertNotNull($confirm->execute(['payment_method' => Method::PIX->value, 'payment_condition' => Condition::CASH->value, 'financial_category_id' => $category->id], false));
        $installment = $invoice->fresh()->installments()->firstOrFail();
        $service = app(AccountReceivableService::class);
        $payment = $service->registerInstallmentPayment($installment, 850, today()->toDateString(), ['discount_amount' => 10, 'user_id' => $invoice->created_by]);
        $this->assertNotNull($payment, (string) $service->getMessage());
        $this->assertSame(0.0, $installment->fresh()->balance_amount);
        $this->assertSame(860.0, $invoice->fresh()->net_value);
        $this->assertSame(90.0, $invoice->fresh()->invoice_discount_amount);
        $this->assertSame(10.0, $payment->discount_amount);
    }

    private function draft(): array
    {
        $order = ServiceOrder::factory()->create(['status' => State::CLOSED, 'travel_value' => 50]);
        User::findOrFail($order->created_by)->companies()->syncWithoutDetaching([$order->company_id => ['role' => 'owner', 'is_active' => true]]);
        $order->customer->companies()->syncWithoutDetaching([$order->company_id => ['is_active' => true, 'type' => '["customer"]']]);
        $invoice = Invoice::create(['company_id' => $order->company_id, 'customer_id' => $order->customer_id, 'invoice_number' => 'DISCOUNT-1', 'invoice_date' => today(), 'status' => Status::PENDING, 'created_by' => $order->created_by]);
        $order->update(['invoice_id' => $invoice->id, 'status' => State::INVOICED]);
        $service = Service::factory()->create(['company_id' => $invoice->company_id]);
        $item = ServiceOrderItem::create(['service_order_id' => $order->id, 'service_id' => $service->id, 'quantity' => 1, 'unit_price' => 1000, 'discount_amount' => 100, 'created_by' => $order->created_by]);

        return [$invoice, $order, $item];
    }
}
