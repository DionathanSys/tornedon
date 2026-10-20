<?php

namespace Tests\Feature\Services\ServiceOrder;

use App\Enum\Product\OriginSalePrice;
use App\Enum\Product\Unit;
use App\Enum\ProductionOrder\DestinationType;
use App\Enum\ProductionOrder\Status as ProductionOrderStatus;
use App\Enum\Requisition\Status as RequisitionStatus;
use App\Enum\ServiceOrder\Priority;
use App\Enum\ServiceOrder\State;
use App\Enum\ServiceOrder\Type;
use App\Enum\Tax\IssExigibility;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Partner;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderItem;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Invoice\Actions\DeleteInvoiceAction;
use App\Services\Invoice\InvoiceService;
use App\Services\ServiceOrder\Actions\CloseServiceOrderAction;
use App\Services\ServiceOrder\CloseServiceOrderWorkflow;
use App\Services\ServiceOrder\InvoiceServiceOrderWorkflow;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceOrderWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_does_not_close_service_order_without_items(): void
    {
        [$user, $company, $customer] = $this->makeBaseContext();

        $serviceOrder = ServiceOrder::query()->create([
            'number' => 'OS-CLOSE-001',
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'order_date' => now()->toDateString(),
            'status' => State::OPEN,
            'priority' => Priority::NORMAL,
            'type' => Type::MAINTENANCE,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $action = new CloseServiceOrderAction($user->id);
        $result = $action->execute($serviceOrder);

        $this->assertNull($result);
        $this->assertSame('Não é possível encerrar ordem de serviço sem itens.', $action->getMessage());
        $this->assertSame(State::OPEN, $serviceOrder->fresh()->status);
    }

    public function test_close_workflow_closes_linked_requisition_by_default(): void
    {
        [$user, $serviceOrder, $requisition] = $this->makeLinkedDocuments();

        $workflow = app(CloseServiceOrderWorkflow::class);
        $result = $workflow->execute($serviceOrder, $user->id, false);

        $this->assertTrue($result, $workflow->getMessage());
        $this->assertSame(State::CLOSED, $serviceOrder->fresh()->status);
        $this->assertSame(RequisitionStatus::CLOSED, $requisition->fresh()->status);
        $this->assertTrue($workflow->closedLinkedRequisition());
    }

    public function test_invoice_workflow_invoices_linked_requisition_in_same_invoice(): void
    {
        [$user, $serviceOrder, $requisition] = $this->makeLinkedDocuments();

        $serviceOrder->update(['status' => State::CLOSED]);

        $workflow = app(InvoiceServiceOrderWorkflow::class);
        $result = $workflow->execute($serviceOrder->fresh(), $user->id);

        $this->assertTrue($result, $workflow->getMessage());
        $this->assertNotNull($workflow->invoice());
        $this->assertSame(State::INVOICED, $serviceOrder->fresh()->status);
        $this->assertSame(RequisitionStatus::INVOICED, $requisition->fresh()->status);
        $this->assertSame($serviceOrder->fresh()->invoice_id, $requisition->fresh()->invoice_id);
        $this->assertSame($workflow->invoice()?->id, $serviceOrder->fresh()->invoice_id);
    }

    public function test_close_workflow_can_close_and_invoice_in_single_request(): void
    {
        [$user, $serviceOrder, $requisition] = $this->makeLinkedDocuments();

        $workflow = app(CloseServiceOrderWorkflow::class);
        $result = $workflow->execute($serviceOrder, $user->id, false, true);

        $this->assertTrue($result, $workflow->getMessage());
        $this->assertNotNull($workflow->invoice());
        $this->assertSame(State::INVOICED, $serviceOrder->fresh()->status);
        $this->assertSame(RequisitionStatus::INVOICED, $requisition->fresh()->status);
        $this->assertSame($serviceOrder->fresh()->invoice_id, $requisition->fresh()->invoice_id);
    }

    public function test_invoice_workflow_invoices_multiple_service_orders_and_linked_requisitions_in_same_invoice(): void
    {
        [$user, $firstServiceOrder, $firstRequisition] = $this->makeLinkedDocuments('001');
        [$ignoredUser, $secondServiceOrder, $secondRequisition] = $this->makeLinkedDocuments(
            '002',
            $user,
            $firstServiceOrder->company,
            $firstServiceOrder->customer,
        );

        $firstServiceOrder->update(['status' => State::CLOSED]);
        $secondServiceOrder->update(['status' => State::CLOSED]);

        $workflow = app(InvoiceServiceOrderWorkflow::class);
        $result = $workflow->execute(new Collection([
            $firstServiceOrder->fresh(),
            $secondServiceOrder->fresh(),
        ]), $user->id);

        $this->assertTrue($result, $workflow->getMessage());
        $this->assertNotNull($workflow->invoice());
        $this->assertSame($firstServiceOrder->fresh()->invoice_id, $secondServiceOrder->fresh()->invoice_id);
        $this->assertSame($firstRequisition->fresh()->invoice_id, $secondRequisition->fresh()->invoice_id);
        $this->assertSame($firstServiceOrder->fresh()->invoice_id, $firstRequisition->fresh()->invoice_id);
    }

    public function test_deleting_invoice_restores_automatically_closed_requisition_to_open_and_allows_reinvoicing(): void
    {
        [$user, $serviceOrder, $requisition] = $this->makeLinkedDocuments();
        $user->companies()->attach($serviceOrder->company_id, ['role' => 'member', 'is_active' => true]);
        $serviceOrder->update(['status' => State::CLOSED]);
        $workflow = app(InvoiceServiceOrderWorkflow::class);

        $this->assertTrue($workflow->execute($serviceOrder->fresh(), $user->id), $workflow->getMessage());
        $invoice = $workflow->invoice();
        $this->assertSame(RequisitionStatus::OPEN, $requisition->fresh()->status_before_invoicing);

        $service = app(InvoiceService::class);
        $this->assertTrue($service->delete($invoice, $user->id), $service->getMessage());

        $this->assertModelMissing($invoice);
        $this->assertSame(State::CLOSED, $serviceOrder->fresh()->status);
        $this->assertNull($serviceOrder->fresh()->invoice_id);
        $this->assertSame(RequisitionStatus::OPEN, $requisition->fresh()->status);
        $this->assertNull($requisition->fresh()->invoice_id);
        $this->assertNull($requisition->fresh()->invoiced_at);
        $this->assertNull($requisition->fresh()->status_before_invoicing);

        $this->assertTrue($workflow->execute($serviceOrder->fresh(), $user->id), $workflow->getMessage());
        $this->assertSame(RequisitionStatus::INVOICED, $requisition->fresh()->status);
        $this->assertSame($workflow->invoice()->id, $requisition->fresh()->invoice_id);
    }

    public function test_deleting_invoice_preserves_previously_closed_requisition_and_restores_production_order(): void
    {
        [$user, $serviceOrder, $requisition] = $this->makeLinkedDocuments();
        $user->companies()->attach($serviceOrder->company_id, ['role' => 'member', 'is_active' => true]);
        $serviceOrder->update(['status' => State::CLOSED]);
        $requisition->update(['status' => RequisitionStatus::CLOSED]);
        $workflow = app(InvoiceServiceOrderWorkflow::class);
        $this->assertTrue($workflow->execute($serviceOrder->fresh(), $user->id), $workflow->getMessage());
        $invoice = $workflow->invoice();
        $productionOrder = ProductionOrder::create([
            'company_id' => $serviceOrder->company_id,
            'customer_id' => $serviceOrder->customer_id,
            'status' => ProductionOrderStatus::INVOICED,
            'destination_type' => DestinationType::DIRECT_DELIVERY,
            'invoice_id' => $invoice->id,
            'completed_at' => now(),
            'created_by' => $user->id,
        ]);

        $service = app(InvoiceService::class);
        $this->assertTrue($service->delete($invoice, $user->id), $service->getMessage());
        $this->assertSame(RequisitionStatus::CLOSED, $requisition->fresh()->status);
        $this->assertSame(ProductionOrderStatus::COMPLETED, $productionOrder->fresh()->status);
        $this->assertNull($productionOrder->fresh()->invoice_id);
        $this->assertNotNull($productionOrder->fresh()->completed_at);
        $this->assertSame($user->id, $productionOrder->fresh()->updated_by);
    }

    public function test_deleting_invoice_created_during_close_restores_requisition_to_open(): void
    {
        [$user, $serviceOrder, $requisition] = $this->makeLinkedDocuments();
        $user->companies()->attach($serviceOrder->company_id, ['role' => 'member', 'is_active' => true]);
        $workflow = app(CloseServiceOrderWorkflow::class);
        $this->assertTrue($workflow->execute($serviceOrder, $user->id, false, true), $workflow->getMessage());

        $service = app(InvoiceService::class);
        $this->assertTrue($service->delete($workflow->invoice(), $user->id), $service->getMessage());
        $this->assertSame(State::CLOSED, $serviceOrder->fresh()->status);
        $this->assertSame(RequisitionStatus::OPEN, $requisition->fresh()->status);
    }

    public function test_failed_deletion_rolls_back_linked_records_and_invoice(): void
    {
        [$user, $serviceOrder, $requisition] = $this->makeLinkedDocuments();
        $user->companies()->attach($serviceOrder->company_id, ['role' => 'member', 'is_active' => true]);
        $serviceOrder->update(['status' => State::CLOSED]);
        $workflow = app(InvoiceServiceOrderWorkflow::class);
        $this->assertTrue($workflow->execute($serviceOrder->fresh(), $user->id), $workflow->getMessage());
        $invoice = $workflow->invoice();

        $this->partialMock(AuditRecorder::class, function ($mock): void {
            $mock->shouldReceive('recordModelEvent')->andThrow(new \RuntimeException('Falha na auditoria'));
        });

        $action = new DeleteInvoiceAction($invoice, $user->id);
        $this->assertFalse($action->execute());
        $this->assertNotNull(Invoice::find($invoice->id));
        $this->assertSame(State::INVOICED, $serviceOrder->fresh()->status);
        $this->assertSame($invoice->id, $serviceOrder->fresh()->invoice_id);
        $this->assertSame(RequisitionStatus::INVOICED, $requisition->fresh()->status);
        $this->assertSame($invoice->id, $requisition->fresh()->invoice_id);
        $this->assertNotNull($requisition->fresh()->invoiced_at);
        $this->assertSame(RequisitionStatus::OPEN, $requisition->fresh()->status_before_invoicing);
    }

    public function test_blocked_deletions_do_not_restore_linked_records(): void
    {
        [$user, $serviceOrder, $requisition] = $this->makeLinkedDocuments();
        $serviceOrder->update(['status' => State::CLOSED]);
        $workflow = app(InvoiceServiceOrderWorkflow::class);
        $this->assertTrue($workflow->execute($serviceOrder->fresh(), $user->id), $workflow->getMessage());
        $invoice = $workflow->invoice();
        $service = app(InvoiceService::class);

        $this->assertFalse($service->delete($invoice, $user->id));
        $user->companies()->attach($serviceOrder->company_id, ['role' => 'member', 'is_active' => true]);
        $invoice->update(['confirmed' => true]);
        $this->assertFalse($service->delete($invoice->fresh(), $user->id));
        $this->assertSame(State::INVOICED, $serviceOrder->fresh()->status);
        $this->assertSame(RequisitionStatus::INVOICED, $requisition->fresh()->status);
        $this->assertSame($invoice->id, $requisition->fresh()->invoice_id);
    }

    public function test_failed_invoicing_rolls_back_automatic_close_and_previous_status(): void
    {
        [$user, $serviceOrder, $requisition] = $this->makeLinkedDocuments();
        $serviceOrder->items()->delete();
        $serviceOrder->update(['status' => State::CLOSED]);
        $workflow = app(InvoiceServiceOrderWorkflow::class);

        $this->assertFalse($workflow->execute($serviceOrder->fresh(), $user->id));
        $this->assertSame(RequisitionStatus::OPEN, $requisition->fresh()->status);
        $this->assertNull($requisition->fresh()->status_before_invoicing);
        $this->assertNull($requisition->fresh()->invoice_id);
    }

    private function makeLinkedDocuments(
        string $suffix = '001',
        ?User $user = null,
        ?Company $company = null,
        ?Partner $customer = null,
    ): array {
        [$user, $company, $customer] = $this->makeBaseContext($user, $suffix, $company, $customer);

        $service = Service::query()->create([
            'company_id' => $company->id,
            'service_code' => 'SRV-WF-'.$suffix,
            'name' => 'Servico Workflow',
            'price' => 100,
            'min_sale_price' => 100,
            'accept_customer_discount' => true,
            'cost' => 50,
            'category' => 'Geral',
            'is_active' => true,
            'requires_approval' => false,
            'iss_exigibility' => IssExigibility::EXIGIVEL,
            'created_by' => $user->id,
        ]);

        $product = Product::query()->create([
            'company_id' => $company->id,
            'product_code' => 'PRD-WF-'.$suffix,
            'name' => 'Produto Workflow',
            'unit' => Unit::UN,
            'origin_sale_price' => OriginSalePrice::FREE,
            'sale_price_value' => 50,
            'has_stock_control' => false,
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        $serviceOrder = ServiceOrder::query()->create([
            'number' => 'OS-WF-'.$suffix,
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'order_date' => now()->toDateString(),
            'status' => State::OPEN,
            'priority' => Priority::NORMAL,
            'type' => Type::MAINTENANCE,
            'payment_method' => null,
            'payment_condition' => null,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        ServiceOrderItem::query()->create([
            'service_order_id' => $serviceOrder->id,
            'service_id' => $service->id,
            'quantity' => 1,
            'unit_price' => 100,
            'discount_amount' => 0,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $requisition = Requisition::query()->create([
            'number' => 'REQ-WF-'.$suffix,
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'service_order_id' => $serviceOrder->id,
            'sale_date' => now()->toDateString(),
            'status' => RequisitionStatus::OPEN,
            'stock_reserved' => false,
            'stock_consumed' => false,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'product_id' => $product->id,
            'unit_of_measure' => 'UN',
            'quantity' => 1,
            'unit_price' => 50,
            'discount_amount' => 0,
            'stock_consumed' => false,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        return [$user, $serviceOrder, $requisition];
    }

    private function makeBaseContext(
        ?User $user = null,
        string $suffix = '001',
        ?Company $company = null,
        ?Partner $customer = null,
    ): array {
        $user ??= User::factory()->create();

        $company ??= Company::query()->create([
            'name' => 'Empresa Workflow '.$suffix,
            'document_number' => '55443322'.str_pad($suffix, 6, '0', STR_PAD_LEFT),
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);

        $customer ??= Partner::query()->create([
            'name' => 'Cliente Workflow '.$suffix,
            'document_type' => 'CPF',
            'document_number' => str_pad($suffix, 11, '9', STR_PAD_LEFT),
            'created_by' => $user->id,
        ]);

        return [$user, $company, $customer];
    }
}
