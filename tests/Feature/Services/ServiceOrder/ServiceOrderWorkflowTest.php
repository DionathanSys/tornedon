<?php

namespace Tests\Feature\Services\ServiceOrder;

use App\Enum\Product\OriginSalePrice;
use App\Enum\Product\Unit;
use App\Enum\Requisition\Status as RequisitionStatus;
use App\Enum\ServiceOrder\Priority;
use App\Enum\ServiceOrder\State;
use App\Enum\ServiceOrder\Type;
use App\Enum\Tax\IssExigibility;
use App\Models\Company;
use App\Models\Partner;
use App\Models\Product;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderItem;
use App\Models\User;
use App\Services\ServiceOrder\Actions\CloseServiceOrderAction;
use App\Services\ServiceOrder\CloseServiceOrderWorkflow;
use App\Services\ServiceOrder\InvoiceServiceOrderWorkflow;
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

    private function makeLinkedDocuments(): array
    {
        [$user, $company, $customer] = $this->makeBaseContext();

        $service = Service::query()->create([
            'company_id' => $company->id,
            'service_code' => 'SRV-WF-001',
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
            'product_code' => 'PRD-WF-001',
            'name' => 'Produto Workflow',
            'unit' => Unit::UN,
            'origin_sale_price' => OriginSalePrice::FREE,
            'sale_price_value' => 50,
            'has_stock_control' => false,
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        $serviceOrder = ServiceOrder::query()->create([
            'number' => 'OS-WF-001',
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
            'number' => 'REQ-WF-001',
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

    private function makeBaseContext(): array
    {
        $user = User::factory()->create();

        $company = Company::query()->create([
            'name' => 'Empresa Workflow',
            'document_number' => '55443322000111',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);

        $customer = Partner::query()->create([
            'name' => 'Cliente Workflow',
            'document_type' => 'CPF',
            'document_number' => '99988877766',
            'created_by' => $user->id,
        ]);

        return [$user, $company, $customer];
    }
}
