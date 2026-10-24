<?php

namespace Tests\Feature\Services\Invoice;

use App\Enum\Invoice\Status;
use App\Enum\Payment\Condition;
use App\Enum\Payment\Method;
use App\Enum\Product\Unit;
use App\Enum\Requisition\Status as RequisitionStatus;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderItem;
use App\Services\Invoice\InvoiceService;
use App\Services\ServiceOrder\Support\ServiceOrderPdfDataFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceTravelAmountTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_includes_full_travel_amount_with_or_without_loaded_relations(): void
    {
        $invoice = $this->createInvoiceWithTravel();

        foreach ([[], ['serviceOrders.items', 'requisitions.items']] as $relations) {
            $record = Invoice::with($relations)->findOrFail($invoice->id);

            $this->assertSame(720.0, $record->services_amount);
            $this->assertSame(890.0, $record->products_amount);
            $this->assertSame(1610.0, $record->gross_amount);
            $this->assertSame(0.0, $record->discount_amount);
            $this->assertSame(1610.0, $record->total_amount);
            $this->assertSame(1610.0, $record->net_value);
        }
    }

    public function test_travel_is_counted_once_per_order_even_with_multiple_items_and_discounts(): void
    {
        $invoice = $this->createInvoiceWithTravel();
        $order = $invoice->serviceOrders()->firstOrFail();
        $item = $order->items()->firstOrFail();
        $item->update(['discount_amount' => 10]);
        ServiceOrderItem::create([
            'service_order_id' => $order->id,
            'service_id' => $item->service_id,
            'quantity' => 1,
            'unit_price' => 100,
            'discount_amount' => 5,
            'created_by' => $order->created_by,
        ]);
        ServiceOrder::factory()->create([
            'invoice_id' => $invoice->id,
            'company_id' => $order->company_id,
            'customer_id' => $order->customer_id,
            'travel_value' => 10.99,
        ]);

        foreach ([[], ['serviceOrders.items', 'requisitions.items']] as $relations) {
            $record = Invoice::with($relations)->findOrFail($invoice->id);

            $this->assertSame(815.99, $record->services_amount);
            $this->assertSame(1720.99, $record->gross_amount);
            $this->assertSame(15.0, $record->discount_amount);
            $this->assertSame(1705.99, $record->total_amount);
        }
    }

    public function test_receivable_and_installments_include_full_travel_amount(): void
    {
        $invoice = $this->createInvoiceWithTravel();
        $service = app(InvoiceService::class);
        $result = $service->generateAccountReceivables($invoice->fresh(), [
            'payment_method' => Method::PIX->value,
            'payment_condition' => Condition::CASH->value,
        ], $invoice->created_by);

        $this->assertNotNull($result, $service->getMessage());
        $receivable = $invoice->accountReceivables()->with('installments')->firstOrFail();
        $this->assertSame(1610.0, (float) $receivable->due_amount);
        $this->assertSame(1610.0, (float) $receivable->installments->sum('due_amount'));
    }

    public function test_pdf_separates_travel_from_services_without_changing_model_totals(): void
    {
        $order = ServiceOrder::factory()->create(['travel_value' => 126]);
        $this->addService($order, 400);

        $data = app(ServiceOrderPdfDataFormatter::class)->format($order->fresh());

        $this->assertSame([
            ['label' => 'Serviços', 'value' => 'R$ 400,00'],
            ['label' => 'Deslocamento', 'value' => 'R$ 126,00'],
            ['label' => 'Valor total', 'value' => 'R$ 526,00'],
        ], $data['summary_lines']);
        $this->assertSame(526.0, $order->fresh()->services_total_amount);
    }

    private function createInvoiceWithTravel(): Invoice
    {
        $order = ServiceOrder::factory()->create(['travel_value' => 220.01]);
        $order->customer->companies()->attach($order->company_id, ['is_active' => true, 'type' => '["customer"]']);
        $invoice = Invoice::create([
            'customer_id' => $order->customer_id,
            'company_id' => $order->company_id,
            'invoice_number' => 'INV-TRAVEL-001',
            'invoice_date' => now()->toDateString(),
            'status' => Status::CONFIRMED,
            'pending' => false,
            'confirmed' => true,
            'canceled' => false,
            'created_by' => $order->created_by,
        ]);
        $order->update(['invoice_id' => $invoice->id]);
        $this->addService($order, 499.99);
        $requisition = Requisition::create([
            'number' => 'REQ-TRAVEL-001',
            'customer_id' => $order->customer_id,
            'company_id' => $order->company_id,
            'service_order_id' => $order->id,
            'invoice_id' => $invoice->id,
            'sale_date' => now()->toDateString(),
            'status' => RequisitionStatus::OPEN,
            'stock_consumed' => false,
            'created_by' => $order->created_by,
        ]);
        $product = Product::factory()->create(['company_id' => $order->company_id]);
        RequisitionItem::create([
            'requisition_id' => $requisition->id,
            'product_id' => $product->id,
            'unit_of_measure' => Unit::UN->value,
            'quantity' => 1,
            'unit_price' => 890,
            'discount_amount' => 0,
            'created_by' => $order->created_by,
        ]);

        return $invoice->fresh();
    }

    private function addService(ServiceOrder $order, float $amount): void
    {
        $service = Service::factory()->create(['company_id' => $order->company_id]);
        ServiceOrderItem::create([
            'service_order_id' => $order->id,
            'service_id' => $service->id,
            'quantity' => 1,
            'unit_price' => $amount,
            'discount_amount' => 0,
            'created_by' => $order->created_by,
        ]);
    }
}
